<?php

namespace App\Http\Controllers\Admin;

use App\Exports\PayoutExport;
use App\Http\Controllers\Controller;
use App\Models\Accounting;
use App\Models\OfflineBank;
use App\Models\Payout;
use App\Models\Role;
use App\Models\Setting;
use App\User;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class PayoutController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('admin_payouts_list');

        $payoutType = $request->get('payout', 'requests'); //requests or history

        $query = Payout::query()->with(['user','user.role','userSelectedBank','userSelectedBank.bank','userSelectedBank.user']);
        if ($payoutType == 'requests') {
            $query->where('status', Payout::$waiting);
        } else {
            $query->where('status', '!=', Payout::$waiting);
        }

        $payouts = $this->filters($query, $request)
            ->paginate(10);

        // Preload Keterangan Tambahan per user untuk kolom baru (hindari N+1 di blade)
        $userIds = $payouts->pluck('user_id')->unique()->values();
        $webinarNotesMap = [];
        if ($userIds->isNotEmpty()) {
            $rows = \App\Models\Webinar::whereIn('creator_id', $userIds)
                ->whereNotNull('additional_note')
                ->where('additional_note', '!=', '')
                ->select('creator_id', 'additional_note')
                ->get();
            foreach ($rows as $r) {
                $webinarNotesMap[$r->creator_id][] = $r->additional_note;
            }
        }

        $roles = Role::all();

        $offlineBanks = OfflineBank::query()
            ->orderBy('created_at', 'desc')
            ->with([
                'specifications'
            ])
            ->get();

        $data = [
            'pageTitle' => ($payoutType == 'requests') ? trans('financial.payouts_requests') : trans('financial.payouts_history'),
            'payouts' => $payouts,
            'roles' => $roles,
            'offlineBanks' => $offlineBanks,
            'webinarNotesMap' => $webinarNotesMap,
        ];

        if ($payoutType == 'requests') {
            $service = app(\App\Services\Espay\EspayDisbursementService::class);
            $data['espayDeposit'] = \Illuminate\Support\Facades\Cache::remember('espay.deposit_balance', 60, fn () => $service->depositBalance());
            $data['espayHeld'] = Payout::query()
                ->where('provider', \App\Services\Espay\EspayDisbursementService::PROVIDER)
                ->where('status', Payout::$waiting)
                ->whereIn('provider_status', \App\Services\Espay\EspayDisbursementService::HOLD_STATUSES)
                ->selectRaw('count(*) as total, coalesce(sum(amount), 0) as amount')
                ->first();
        }

        $user_ids = $request->get('user_ids', []);

        if (!empty($user_ids)) {
            $data['users'] = User::select('id', 'full_name')
                ->whereIn('id', $user_ids)->get();
        }

        return view('admin.financial.payout.lists', $data);
    }

    private function filters($query, $request)
    {
        $from = $request->get('from', null);
        $to = $request->get('to', null);
        $search = $request->get('search', null);
        $user_ids = $request->get('user_ids', []);
        $role_id = $request->get('role_id', null);
        $account_type = $request->get('account_type', null);
        $sort = $request->get('sort', null);

        if (!empty($search)) {
            $ids = User::where('full_name', 'like', "%$search%")->pluck('id')->toArray();
            $user_ids = array_merge($user_ids, $ids);
        }

        if (!empty($role_id)) {
            $role = Role::where('id', $role_id)->first();

            if (!empty($role)) {
                $ids = $role->users()->pluck('id')->toArray();
                $user_ids = array_merge($user_ids, $ids);
            }
        }

        $query = fromAndToDateFilter($from, $to, $query, 'created_at');

        if (!empty($user_ids) and count($user_ids)) {
            $query->whereIn('user_id', $user_ids);
        }

        if (!empty($account_type)) {
            $query->where('account_bank_name', $account_type);
        }

        if (!empty($sort)) {
            switch ($sort) {
                case 'amount_asc':
                    $query->orderBy('amount', 'asc');
                    break;
                case 'amount_desc':
                    $query->orderBy('amount', 'desc');
                    break;
                case 'created_at_asc':
                    $query->orderBy('created_at', 'asc');
                    break;
                case 'created_at_desc':
                    $query->orderBy('created_at', 'desc');
                    break;
            }
        } else {
            $query->orderBy('created_at', 'desc');
        }

        return $query;
    }

    public function reject($id)
    {
        $this->authorize('admin_payouts_reject');

        $payout = Payout::findOrFail($id);

        if ($payout->status === Payout::$processing) {
            return back()->with(['toast' => ['title' => trans('public.request_failed'), 'msg' => 'Pencairan sedang diproses Espay. Status akan berubah otomatis setelah ada hasil transfer.', 'status' => 'error']]);
        }
        $payout->update(['status' => Payout::$reject]);

        return back();
    }

    public function revert($id)
    {
        $this->authorize('admin_payouts_reject'); // Reusing this permission

        $payout = Payout::findOrFail($id);

        if ($payout->status === Payout::$processing) {
            return back()->with(['toast' => ['title' => trans('public.request_failed'), 'msg' => 'Pencairan sedang diproses Espay. Status akan berubah otomatis setelah ada hasil transfer.', 'status' => 'error']]);
        }
        
        // Cek jika statusnya sebelumnya 'done', kita juga harus menghapus potongan akuntansi?
        // Saat ini, user request hanya merevert status ke waiting
        $payout->update(['status' => Payout::$waiting]);

        $toastData = [
            'title' => 'Dibatalkan',
            'msg' => 'Penarikan telah dibatalkan dan dikembalikan ke halaman Permintaan Payout.',
            'status' => 'success'
        ];

        return back()->with(['toast' => $toastData]);
    }

    public function payout($id)
    {
        $this->authorize('admin_payouts_payout');

        $payout = Payout::findOrFail($id);
        $getFinancialSettings = getFinancialSettings();

        if ($payout->user->getPayout(true) < $getFinancialSettings['minimum_payout']) {
            $toastData = [
                'title' => trans('public.request_failed'),
                'msg' => trans('public.income_los_then_minimum_payout'),
                'status' => 'error'
            ];
            return back()->with(['toast' => $toastData]);
        }

        // --- ESPAY DISBURSEMENT ---
        if ($payout->status !== Payout::$waiting or empty($payout->userSelectedBank)) {
            return back()->with(['toast' => ['title' => trans('public.request_failed'), 'msg' => 'Permintaan pencairan sudah diproses atau rekening tidak ditemukan.', 'status' => 'error']]);
        }

        try {
            $payout = app(\App\Services\Espay\EspayDisbursementService::class)
                ->disburse($payout->user, $payout->userSelectedBank, (float) $payout->amount, 'Payout Request #' . $payout->id, $payout);
        } catch (\Throwable $e) {
            return back()->with(['toast' => ['title' => 'Gagal Mencairkan via Espay', 'msg' => $e->getMessage(), 'status' => 'error']]);
        }

        if ($payout->status === Payout::$reject) {
            return back()->with(['toast' => ['title' => 'Gagal Mencairkan via Espay', 'msg' => $payout->providerData()['result']['reason'] ?? 'Transfer ditolak Espay.', 'status' => 'error']]);
        }

        if ($payout->status === Payout::$waiting) {
            $hold = $payout->providerData()['hold'] ?? [];
            $msg = ($hold['reason'] ?? '') === \App\Services\Espay\EspayDisbursementService::HOLD_INSUFFICIENT
                ? 'Saldo deposit Espay ' . handlePrice($hold['deposit'] ?? 0) . ' kurang dari kebutuhan ' . handlePrice($hold['needed'] ?? 0) . '. Isi saldo deposit; payout akan diproses otomatis.'
                : 'Saldo deposit Espay tidak dapat dicek. Payout akan dicoba lagi otomatis.';

            return back()->with(['toast' => ['title' => 'Payout Ditahan', 'msg' => $msg, 'status' => 'error']]);
        }

        return back()->with(['toast' => ['title' => trans('public.request_success'), 'msg' => $payout->status === Payout::$done ? 'Dana berhasil dikirim via Espay.' : 'Transfer via Espay sedang diproses. Status akan diperbarui otomatis.', 'status' => 'success']]);
    }

    /**
     * Proses payout secara manual (tanpa API).
     * Admin melakukan transfer bank secara manual, lalu klik tombol ini untuk menandai selesai.
     */
    public function payoutManual($id)
    {
        $this->authorize('admin_payouts_payout');

        $payout = Payout::findOrFail($id);
        $getFinancialSettings = getFinancialSettings();

        if ($payout->user->getPayout(true) < $getFinancialSettings['minimum_payout']) {
            $toastData = [
                'title' => trans('public.request_failed'),
                'msg'   => trans('public.income_los_then_minimum_payout'),
                'status' => 'error'
            ];
            return back()->with(['toast' => $toastData]);
        }

        $amountToDeduct = $payout->amount;
        $incomeBalance  = $payout->user->getIncomeBalance();

        if ($incomeBalance > 0) {
            $deductIncome = min($incomeBalance, $amountToDeduct);
            Accounting::create([
                'creator_id'   => auth()->user()->id,
                'user_id'      => $payout->user_id,
                'amount'       => $deductIncome,
                'type'         => Accounting::$deduction,
                'type_account' => Accounting::$income,
                'description'  => trans('financial.payout_request') . ' (Manual)',
                'created_at'   => time(),
            ]);
            $amountToDeduct -= $deductIncome;
        }

        if ($amountToDeduct > 0) {
            Accounting::create([
                'creator_id'   => auth()->user()->id,
                'user_id'      => $payout->user_id,
                'amount'       => $amountToDeduct,
                'type'         => Accounting::$deduction,
                'type_account' => Accounting::$asset,
                'description'  => trans('financial.payout_request') . ' (Manual)',
                'created_at'   => time(),
            ]);
        }

        $notifyOptions = [
            '[payout.amount]'  => $payout->amount,
            '[payout.account]' => $payout->account_bank_name
        ];
        sendNotification('payout_proceed', $notifyOptions, $payout->user_id);

        $payout->update(['status' => Payout::$done]);

        $toastData = [
            'title'  => 'Payout Manual Berhasil',
            'msg'    => 'Payout telah ditandai selesai. Pastikan Anda sudah melakukan transfer bank secara manual.',
            'status' => 'success'
        ];
        return back()->with(['toast' => $toastData]);
    }

    public function exportExcel(Request $request)
    {
        $this->authorize('admin_payouts_export_excel');

        $payoutType = $request->get('payout', 'requests'); //requests or history

        $query = Payout::query();
        if ($payoutType == 'requests') {
            $query->where('status', Payout::$waiting);
        } else {
            $query->where('status', '!=', Payout::$waiting);
        }

        $payouts = $this->filters($query, $request)->get();

        $export = new PayoutExport($payouts);

        $filename = ($payoutType == 'requests') ? trans('financial.payouts_requests') : trans('financial.payouts_history');

        return Excel::download($export, $filename . '.xlsx');
    }
}
