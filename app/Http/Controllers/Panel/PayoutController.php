<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Services\Espay\EspayDisbursementService;
use Illuminate\Http\Request;

class PayoutController extends Controller
{
    /**
     * Sekolah yang boleh dicakup oleh sebuah akun.
     *
     * Ini satu-satunya definisi "sekolah milik saya" dan dipakai bersama oleh
     * index(), getTeachersBySchool() dan requestPayout() supaya dropdown dan
     * submission tidak pernah berbeda cakupan.
     *
     * @return array<int>
     */
    private function getAccountSchoolIds($user)
    {
        if ($user->isSchool()) {
            // Akun sekolah hanya boleh melihat/mencairkan pengajar sekolahnya sendiri.
            return [$user->id];
        }

        if ($user->isOrganization()) {
            $ids = \App\Models\Webinar::where('creator_id', $user->id)
                ->where('type', \App\Models\Webinar::$offlineClass)
                ->whereNotNull('school_id')
                ->distinct()->pluck('school_id')->toArray();

            $ids = array_merge($ids, \App\User::where('organ_id', $user->id)
                ->where('role_name', \App\Models\Role::$school)
                ->pluck('id')->toArray());

            $ids = array_values(array_unique(array_filter($ids)));

            if (!empty($ids)) {
                return $ids;
            }

            // Tidak ada relasi sekolah yang tercatat -> seluruh sekolah aktif bisa dipilih.
            return \App\User::where('role_name', \App\Models\Role::$school)
                ->where('status', 'active')->pluck('id')->toArray();
        }

        return [];
    }

    /**
     * Pengajar yang boleh dicairkan oleh sebuah akun, optionally dibatasi satu sekolah.
     *
     * Satu-satunya definisi "pengajar yang boleh saya bayar", dipakai bersama oleh
     * index(), getTeachersBySchool() dan requestPayout() supaya pilihan pada dropdown
     * selalu bisa diverifikasi ulang di backend.
     *
     * @param  int|null  $schoolId  Batasi ke satu sekolah (harus milik akun tersebut).
     * @return array<int>
     */
    private function getPayableTeacherIds($user, $schoolId = null)
    {
        if (!$user->isOrganization() && !$user->isSchool()) {
            return [];
        }

        $allowedSchoolIds = $this->getAccountSchoolIds($user);
        if (!empty($schoolId) && $schoolId !== 'all') {
            // Anti IDOR: school_id dari request hanya sah bila sekolah itu milik akun ini.
            $allowedSchoolIds = array_values(array_intersect($allowedSchoolIds, [(int) $schoolId]));
        }

        $ids = \App\User::where('role_name', \App\Models\Role::$teacher)
            ->where('organ_id', $user->id)
            ->pluck('id')->toArray();

        if (!empty($allowedSchoolIds)) {
            // Sekolah menautkan pengajar lewat webinars.creator_id = schoolId (offline_class).
            $ids = array_merge($ids, \App\Models\Webinar::whereIn('creator_id', $allowedSchoolIds)
                ->whereNotNull('teacher_id')
                ->pluck('teacher_id')->toArray());

            $ids = array_merge($ids, \App\User::where('role_name', \App\Models\Role::$teacher)
                ->where(function ($query) use ($allowedSchoolIds) {
                    $query->whereIn('school_id', $allowedSchoolIds)
                        ->orWhereIn('organ_id', $allowedSchoolIds);
                })
                ->pluck('id')->toArray());
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * Pengajar milik akun yang sudah punya rekening, terurut nama.
     *
     * @param  int|null  $schoolId
     * @return \Illuminate\Support\Collection
     */
    private function getPayableInstructors($user, $schoolId = null)
    {
        $ids = $this->getPayableTeacherIds($user, $schoolId);

        if (empty($ids)) {
            return collect();
        }

        return \App\User::whereIn('id', $ids)
            ->where('role_name', \App\Models\Role::$teacher)
            ->where('status', 'active')
            ->orderBy('full_name', 'asc')
            ->get();
    }

    /**
     * Saldo yang bisa dicairkan per pengajar dari saldo akun pemilik.
     *
     * Mirrors the per-instructor math so paying a single instructor can never move
     * the whole account balance into that one bank account.
     */
    private function getInstructorSubBalance($owner, $instructor)
    {
        // Restriksi ke order item milik pengajar ini. `webinar` dan `reserveMeeting`
        // adalah relasi milik OrderItem, jadi closure harus dipasang pada whereHas('orderItem').
        $linkToInstructor = function ($instructor) {
            return function ($orderItemQuery) use ($instructor) {
                $orderItemQuery->whereHas('webinar', function ($webinarQuery) use ($instructor) {
                    $webinarQuery->where('teacher_id', $instructor->id);
                })->orWhereHas('reserveMeeting', function ($meetingQuery) use ($instructor) {
                    $meetingQuery->whereHas('meeting', function ($innerQuery) use ($instructor) {
                        $innerQuery->where('creator_id', $instructor->id);
                    });
                });
            };
        };

        $income = \App\Models\Accounting::where('user_id', $owner->id)
            ->where('type_account', \App\Models\Accounting::$income)
            ->where('type', \App\Models\Accounting::$addiction)
            ->where('system', false)
            ->where('tax', false)
            ->whereHas('orderItem', $linkToInstructor($instructor))
            ->sum('amount');

        $refunds = \App\Models\Accounting::where('user_id', $owner->id)
            ->where('type_account', \App\Models\Accounting::$income)
            ->where('type', \App\Models\Accounting::$deduction)
            ->where('system', false)
            ->where('tax', false)
            ->whereNotNull('order_item_id')
            ->whereHas('orderItem', $linkToInstructor($instructor))
            ->sum('amount');

        $paidOut = 0;
        if (!empty($instructor->selectedBank)) {
            $instructorBankIds = \App\Models\UserSelectedBank::where('user_id', $instructor->id)->pluck('id')->toArray();
            if (!empty($instructorBankIds)) {
                $paidOut = \App\Models\Payout::where('user_id', $owner->id)
                    ->whereIn('status', [Payout::$waiting, Payout::$done])
                    ->whereIn('user_selected_bank_id', $instructorBankIds)
                    ->sum('amount');
            }
        }

        return max(0, $income - $refunds - $paidOut);
    }

    /**
     * Saldo siap cair yang ditampilkan dan divalidasi untuk akun ini.
     *
     * Untuk pengajar/user biasa ini saldo miliknya sendiri. Untuk organisasi/sekolah,
     * yang bisa dicairkan adalah jumlah saldo masing-masing pengajar di bawahnya,
     * bukan saldo akun (yang di data ini selalu 0 karena pembukuan ada di level pengajar).
     */
    private function getAccountReadyPayout($user)
    {
        if (!$user->isOrganization() && !$user->isSchool()) {
            return $user->getPayout();
        }

        $total = 0;
        foreach ($this->getPayableInstructors($user) as $instructor) {
            $total += $this->getInstructorSubBalance($user, $instructor);
        }

        return $total;
    }

    /**
     * Label rekening untuk ditampilkan di dropdown, mis. "Nama (Bank - 123 - Holders)".
     *
     * Satu-satunya definisi label ini; dipakai oleh render awal di view maupun
     * respons getTeachersBySchool() supaya keduanya tidak pernah berbeda.
     */
    private function getInstructorBankLabel($instructor)
    {
        $bankTitle = '-';
        $accountNumber = '-';
        $accountName = '-';

        if (!empty($instructor->selectedBank) && !empty($instructor->selectedBank->bank)) {
            $bankTitle = $instructor->selectedBank->bank->title;

            $specs = \App\Models\UserSelectedBankSpecification::where('user_selected_bank_id', $instructor->selectedBank->id)->get();
            foreach ($specs as $spec) {
                $translation = \DB::table('user_bank_specification_translations')
                    ->where('user_bank_specification_id', $spec->user_bank_specification_id)
                    ->where('locale', 'id')
                    ->first();

                if (empty($translation)) {
                    continue;
                }

                $name = strtolower($translation->name);
                if (strpos($name, 'nama pemilik') !== false || strpos($name, 'account name') !== false) {
                    $accountName = $spec->value;
                }
                if (strpos($name, 'nomor rekening') !== false || strpos($name, 'account number') !== false) {
                    $accountNumber = $spec->value;
                }
            }
        }

        if ($bankTitle === '-') {
            return $instructor->full_name . ' (Belum mengisi data rekening)';
        }

        return $instructor->full_name . " ({$bankTitle} - {$accountNumber} - {$accountName})";
    }

    public function index(Request $request)
    {
        $this->authorize("panel_financial_payout");

        $user = auth()->user();
        $userBankIds = \App\Models\UserSelectedBank::where('user_id', $user->id)->pluck('id')->toArray();

        $payouts = Payout::where(function($query) use ($user, $userBankIds) {
                $query->where('user_id', $user->id);
                if (!empty($userBankIds)) {
                    $query->orWhereIn('user_selected_bank_id', $userBankIds);
                }
            })
            ->orderBy('status', 'asc')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        $organizationInstructors = collect();
        $schools = collect();
        if ($user->isOrganization() || $user->isSchool()) {
            // `payoutable` dan `bank_label` hanya attribute runtime untuk membatasi input
            // nominal custom dan merender label rekening; tidak pernah disimpan ke database.
            $organizationInstructors = $this->getPayableInstructors($user)->each(function ($instructor) use ($user) {
                $instructor->setAttribute('payoutable', $this->getInstructorSubBalance($user, $instructor));
                $instructor->setAttribute('bank_label', $this->getInstructorBankLabel($instructor));
            });

            $schoolIds = $this->getAccountSchoolIds($user);
            if (!empty($schoolIds)) {
                $schools = \App\User::whereIn('id', $schoolIds)
                    ->where('role_name', \App\Models\Role::$school)
                    ->orderBy('full_name', 'asc')
                    ->get(['id', 'full_name']);
            }
        }

        $data = [
            'pageTitle' => trans('financial.payout_request'),
            'payouts' => $payouts,
            // Semua nominal sudah net setelah pembagian persentase (Accounting income, bukan Sale gross)
            // Saldo Akun disamakan dengan dashboard (getAccountingBalance) agar tidak 0 untuk pengajar
            'accountCharge' => $user->isTeacher() ? $user->getAccountingBalance() : $user->getAccountingCharge(),
            'readyPayout' => $this->getAccountReadyPayout($user),
            'totalIncome' => $user->getIncome(),
            'organizationInstructors' => $organizationInstructors,
            'schools' => $schools,
        ];

        return view(getTemplate() . '.panel.financial.payout', $data);
    }

    public function requestPayout()
    {
        $this->authorize("panel_financial_payout");

        $user = auth()->user();
        $getFinancialSettings = getFinancialSettings();
        $minPayout = (int) ($getFinancialSettings['minimum_payout'] ?? 0);

        $bankUser = $user;
        $targetInstructor = null;

        if ($user->isOrganization() || $user->isSchool()) {
            $instructorId = request()->input('instructor_id');

            if ($instructorId === 'all') {
                return $this->requestPayoutAllInstructors($user, $minPayout);
            }

            if (empty($instructorId)) {
                $toastData = [
                    'title' => trans('public.request_failed'),
                    'msg' => 'Silakan pilih rekening tujuan pencairan.',
                    'status' => 'error'
                ];
                return back()->with(['toast' => $toastData]);
            }

            if ($instructorId == $user->id) {
                if (empty($user->selectedBank)) {
                    $toastData = [
                        'title' => trans('public.request_failed'),
                        'msg' => trans('site.check_identity_settings'),
                        'status' => 'error'
                    ];
                    return back()->with(['toast' => $toastData]);
                }
            } else {
                // Re-verify server side against the exact same source the dropdown was built from,
                // so a tampered instructor_id can never pay an unrelated teacher.
                $payableIds = $this->getPayableTeacherIds($user, request()->input('school_id'));
                if (!in_array((int) $instructorId, $payableIds, true)) {
                    $toastData = [
                        'title' => trans('public.request_failed'),
                        'msg' => 'Rekening tujuan tidak valid atau belum diatur.',
                        'status' => 'error'
                    ];
                    return back()->with(['toast' => $toastData]);
                }

                $targetInstructor = \App\User::where('id', $instructorId)
                    ->where('role_name', \App\Models\Role::$teacher)
                    ->where('status', 'active')
                    ->first();

                if (empty($targetInstructor) || empty($targetInstructor->selectedBank)) {
                    $toastData = [
                        'title' => trans('public.request_failed'),
                        'msg' => 'Rekening tujuan tidak valid atau belum diatur.',
                        'status' => 'error'
                    ];
                    return back()->with(['toast' => $toastData]);
                }

                $bankUser = $targetInstructor;
            }
        } elseif (empty($user->selectedBank)) {
            $toastData = [
                'title' => trans('public.request_failed'),
                'msg' => trans('site.check_identity_settings'),
                'status' => 'error'
            ];
            return back()->with(['toast' => $toastData]);
        }

        // Nominal maksimum untuk rekening tujuan ini.
        // Untuk organisasi/sekolah ini WAJIB saldo pengajar yang dipilih, bukan saldo akun,
        // supaya mencairkan satu pengajar tidak mengirim seluruh saldo akun ke rekening dia.
        $maxDrawable = $targetInstructor
            ? $this->getInstructorSubBalance($user, $targetInstructor)
            : $user->getPayout();

        $getUserPayout = $maxDrawable;

        // Kategori transfer: field hanya dirender untuk akun non-organisasi
        $transferCategory = request()->input('transfer_category', 'semua');
        if (!$user->isOrganization() && $transferCategory === 'lainnya') {
            $customAmount = (int) request()->input('custom_amount');
            if ($customAmount <= 0) {
                return back()->with(['toast' => ['title' => trans('public.request_failed'), 'msg' => 'Masukkan nominal yang valid.', 'status' => 'error']]);
            }
            if ($customAmount < $minPayout) {
                return back()->with(['toast' => ['title' => trans('public.request_failed'), 'msg' => 'Nominal minimal pencairan adalah ' . handlePrice($minPayout), 'status' => 'error']]);
            }
            if ($customAmount > $maxDrawable) {
                return back()->with(['toast' => ['title' => trans('public.request_failed'), 'msg' => 'Nominal melebihi saldo siap cair (' . handlePrice($maxDrawable) . ')', 'status' => 'error']]);
            }
            $getUserPayout = $customAmount;
        }

        if ($getUserPayout < $minPayout) {
            $toastData = [
                'title' => trans('public.request_failed'),
                'msg' => trans('public.income_los_then_minimum_payout'),
                'status' => 'error'
            ];
            return back()->with(['toast' => $toastData]);
        }

        // ================================================================
        // ATURAN BISNIS PENCAIRAN DANA
        // ================================================================
        // 1. Mengajar di Sekolah (offline_class) → otomatis cair
        // 2. Kursus Private (online / in_person) → otomatis cair setelah absen KELUAR
        // 3. Pendapatan lain (webinar biasa/dll) → otomatis cair
        // ================================================================

        // Cek apakah pengajar punya pendapatan dari kursus private (ONLINE atau TATAP MUKA)
        // Cek target absensi
        $attendanceTargetId = null;
        if (!$user->isOrganization() && !$user->isSchool()) {
            $attendanceTargetId = $user->id; // Normal teacher checks themselves
        } elseif (!empty($targetInstructor)) {
            $attendanceTargetId = $targetInstructor->id;
        } elseif ($bankUser->id === $user->id) {
            // Organisasi/sekolah mencairkan ke rekeningnya sendiri
            $attendanceTargetId = $user->id;
        }

        if (!empty($attendanceTargetId)) {
            $hasPrivateIncome = \DB::table('sales')
                ->join('reserve_meetings', 'reserve_meetings.sale_id', '=', 'sales.id')
                ->where('sales.seller_id', $attendanceTargetId)
                ->whereIn('reserve_meetings.meeting_type', ['online', 'in_person'])
                ->whereNull('sales.refund_at')
                ->exists();

            // Jika ada pendapatan dari kursus private → wajib sudah absen KELUAR semua sesi
            if ($hasPrivateIncome) {
                // Cek apakah ada sesi private yang sudah terbayar tapi pengajar BELUM absen keluar
                $incompleteCheckout = \DB::table('sales')
                    ->join('reserve_meetings', 'reserve_meetings.sale_id', '=', 'sales.id')
                    ->leftJoin('instructor_attendances', 'instructor_attendances.reserve_meeting_id', '=', 'reserve_meetings.id')
                    ->where('sales.seller_id', $attendanceTargetId)
                    ->whereIn('reserve_meetings.meeting_type', ['online', 'in_person'])
                    ->where('reserve_meetings.date', '<=', time())
                    ->whereNull('sales.refund_at')
                    ->where(function ($q) {
                        // Belum absen sama sekali, ATAU sudah absen masuk tapi belum absen keluar
                        $q->whereNull('instructor_attendances.id')
                            ->orWhereNull('instructor_attendances.checked_out_at');
                    })
                    ->exists();

                if ($incompleteCheckout) {
                    \App\Models\Payout::create([
                        'user_id' => $user->id,
                        'user_selected_bank_id' => $bankUser->selectedBank->id,
                        'amount' => $getUserPayout,
                        'status' => \App\Models\Payout::$reject,
                        'is_automatic' => true,
                        'created_at' => time(),
                    ]);

                    $toastData = [
                        'title' => trans('public.request_failed'),
                        'msg' => 'Pencairan dana baru bisa dilakukan setelah pengajar terkait (atau Anda) menyelesaikan absensi keluar di semua sesi mengajar.',
                        'status' => 'error'
                    ];
                    return back()->with(['toast' => $toastData]);
                }
            }
        }

        // ============================================================
        // Semua syarat terpenuhi → transfer otomatis via Espay Disbursement
        // (saldo dipotong saat diajukan, status "Diproses", lalu selesai / dikembalikan otomatis)
        // ============================================================
        $result = $this->disburseViaEspay($user, $bankUser, $getUserPayout,
            'Payout ' . $user->full_name . ((($user->isOrganization() || $user->isSchool()) and $bankUser->id !== $user->id) ? ' to ' . $bankUser->full_name : ''));

        return back()->with(['toast' => $this->getToastData($result['ok'] ? 'success' : 'error', $result['msg'])]);
    }

    public function cancelPayout(Request $request, $id)
    {
        $this->authorize("panel_financial_payout");

        $user = auth()->user();

        $payout = Payout::where('id', $id)
            ->where('user_id', $user->id)
            ->where('status', Payout::$waiting)
            ->first();

        if (empty($payout)) {
            return response()->json([
                'code' => 404,
                'title' => trans('public.request_failed'),
                'text' => 'Permintaan pencairan dana tidak ditemukan atau status sudah diproses.',
            ]);
        }

        // Hapus payout karena status masih waiting dan belum mengurangi saldo di table accountings
        $payout->delete();

        return response()->json([
            'code' => 200,
            'title' => trans('public.request_success'),
            'text' => 'Permintaan pencairan dana berhasil dibatalkan.',
            // Halaman akan auto-reload via main.min.js karena tidak ada redirect_to
        ]);
    }
    /**
     * Pencairan via Espay Disbursement. Saldo dipotong saat diajukan; bila transfer gagal, saldo dikembalikan otomatis.
     *
     * @return array{ok: bool, msg: string, payout: ?Payout}
     */
    private function disburseViaEspay($owner, $bankUser, $amount, string $remark, bool $notifyAdmin = true, $selectedBank = null): array
    {
        $selectedBank = $selectedBank ?: $bankUser->selectedBank;

        try {
            $payout = app(EspayDisbursementService::class)->disburse($owner, $selectedBank, (float) $amount, $remark);
        } catch (\RuntimeException $e) {
            $message = str_starts_with($e->getMessage(), 'Espay ')
                ? 'Gagal terhubung ke layanan pencairan Espay. Silakan coba beberapa saat lagi.'
                : $e->getMessage();

            \Log::channel('espay')->warning('[Payout] pengajuan gagal', ['user_id' => $owner->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'msg' => $message, 'payout' => null];
        } catch (\Throwable $e) {
            \Log::channel('espay')->error('[Payout] pengajuan error', ['user_id' => $owner->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'msg' => 'Pencairan dana gagal diproses. Silakan coba lagi.', 'payout' => null];
        }

        if ($payout->status === Payout::$reject) {
            $reason = $payout->providerData()['result']['reason'] ?? 'Transfer ditolak';

            return ['ok' => false, 'msg' => 'Pencairan dana gagal: ' . $reason . '. Saldo Anda tidak berkurang.', 'payout' => $payout];
        }

        if ($notifyAdmin) {
            sendNotification('payout_request_admin', ['[amount]' => handlePrice($payout->amount), '[u.name]' => $owner->full_name], 1);
        }

        if ($payout->status === Payout::$waiting) {
            // ditahan karena saldo deposit Espay; diproses ulang otomatis oleh espay:payout-sync
            $message = 'Permintaan pencairan dana diterima dan masuk antrean. Dana akan dikirim otomatis dalam beberapa saat; Anda masih dapat membatalkannya selama status Menunggu.';
        } else {
            $message = $payout->status === Payout::$done
                ? 'Pencairan dana berhasil. Dana sudah dikirim ke rekening tujuan.'
                : 'Pencairan dana sedang diproses. Dana akan masuk ke rekening tujuan setelah transfer dikonfirmasi bank.';
        }

        return ['ok' => true, 'msg' => $message, 'payout' => $payout];
    }

    private function getToastData($status, $msg)
    {
        return [
            'title' => $status == 'error' ? trans('public.request_failed') : trans('public.request_success'),
            'msg' => $msg,
            'status' => $status
        ];
    }

    private function requestPayoutAllInstructors($user, $minPayout)
    {
        $organizationInstructors = $this->getPayableInstructors($user);

        if ($organizationInstructors->isEmpty()) {
            return back()->with(['toast' => $this->getToastData('error', 'Pencairan gagal. Anda belum memiliki pengajar di bawah naungan organisasi.')]);
        }
        
        $successCount = 0;

        foreach ($organizationInstructors as $instructor) {
            if (empty($instructor->selectedBank)) {
                continue;
            }

            $subBalance = $this->getInstructorSubBalance($user, $instructor);

            if ($subBalance >= $minPayout) {
                // Execute individual payout per instructor directly (mirrored logic from requestPayout)
                
                // Cek absensi
                $incompleteCheckout = \DB::table('sales')
                    ->join('reserve_meetings', 'reserve_meetings.sale_id', '=', 'sales.id')
                    ->leftJoin('instructor_attendances', 'instructor_attendances.reserve_meeting_id', '=', 'reserve_meetings.id')
                    ->where('sales.seller_id', $instructor->id)
                    ->whereIn('reserve_meetings.meeting_type', ['online', 'in_person'])
                    ->where('reserve_meetings.date', '<=', time())
                    ->whereNull('sales.refund_at')
                    ->where(function ($q) {
                        $q->whereNull('instructor_attendances.id')
                            ->orWhereNull('instructor_attendances.checked_out_at');
                    })
                    ->exists();

                if (!$incompleteCheckout) {
                    $result = $this->disburseViaEspay($user, $instructor, $subBalance, 'Payout ' . $user->full_name . ' to ' . $instructor->full_name, false);
                    if ($result['ok']) {
                        $successCount++;
                    }
                } else {
                    \App\Models\Payout::create([
                        'user_id' => $user->id,
                        'user_selected_bank_id' => $instructor->selectedBank->id,
                        'amount' => $subBalance,
                        'status' => \App\Models\Payout::$reject,
                        'is_automatic' => true,
                        'created_at' => time(),
                    ]);
                }
            }
        }

        if ($successCount > 0) {
            return back()->with(['toast' => $this->getToastData('success', "Pencairan dana massal berhasil. $successCount pengajar telah diproses.")]);
        }
        
        return back()->with(['toast' => $this->getToastData('error', 'Pencairan gagal diproses ke semua pengajar. Cek kelengkapan absensi dan saldo tiap pengajar.')]);
    }
    public function retryPayout(Request $request, $id)
    {
        $this->authorize("panel_financial_payout");
        $user = auth()->user();

        $payout = Payout::where('id', $id)
            ->where('user_id', $user->id)
            ->where('status', Payout::$reject)
            ->first();

        if (empty($payout) || empty($payout->userSelectedBank)) {
            return response()->json([
                'code' => 404,
                'title' => trans('public.request_failed'),
                'text' => 'Permintaan pencairan dana tidak ditemukan atau sudah diproses.',
            ]);
        }
        
        $bankUser = \App\User::find($payout->userSelectedBank->user_id);
        if(empty($bankUser)) {
             $bankUser = $user;
        }

        // Validate absensi checkout
        $hasPrivateIncome = \DB::table('sales')
            ->join('reserve_meetings', 'reserve_meetings.sale_id', '=', 'sales.id')
            ->where('sales.seller_id', $bankUser->id)
            ->whereIn('reserve_meetings.meeting_type', ['online', 'in_person'])
            ->whereNull('sales.refund_at')
            ->exists();

        if ($hasPrivateIncome) {
            $incompleteCheckout = \DB::table('sales')
                ->join('reserve_meetings', 'reserve_meetings.sale_id', '=', 'sales.id')
                ->leftJoin('instructor_attendances', 'instructor_attendances.reserve_meeting_id', '=', 'reserve_meetings.id')
                ->where('sales.seller_id', $bankUser->id)
                ->whereIn('reserve_meetings.meeting_type', ['online', 'in_person'])
                ->where('reserve_meetings.date', '<=', time())
                ->whereNull('sales.refund_at')
                ->where(function ($q) {
                    $q->whereNull('instructor_attendances.id')
                        ->orWhereNull('instructor_attendances.checked_out_at');
                })
                ->exists();

            if ($incompleteCheckout) {
                return response()->json([
                    'code' => 400,
                    'title' => trans('public.request_failed'),
                    'text' => 'Pengajar terkait belum menyelesaikan absensi keluar di semua sesi mengajar. Sistem membatalkan transfer.',
                ]);
            }
        }
        
        // Validate balance
        $amountToDeduct = $payout->amount;
        $incomeBalance = $user->getIncomeBalance();

        if ($incomeBalance < $amountToDeduct) {
             return response()->json([
                'code' => 400,
                'title' => trans('public.request_failed'),
                'text' => 'Saldo akun Anda tidak mencukupi untuk memproses ulang pencairan ini.',
            ]);
        }

        if ($payout->provider_status === 'retried') {
            return response()->json(['code' => 400, 'title' => trans('public.request_failed'), 'text' => 'Pencairan ini sudah pernah diajukan ulang.']);
        }

        $result = $this->disburseViaEspay($user, $bankUser, (float) $payout->amount, 'Retry payout ' . $bankUser->full_name, true, $payout->userSelectedBank);

        if ($result['ok']) {
            $payout->update(['provider_status' => 'retried']);
        }

        return response()->json([
            'code' => $result['ok'] ? 200 : 400,
            'title' => $result['ok'] ? trans('public.request_success') : trans('public.request_failed'),
            'text' => $result['msg'],
        ]);
    }

    public function getTeachersBySchool(Request $request)
    {
        $this->authorize("panel_financial_payout");

        $schoolId = $request->input('school_id');
        $user = auth()->user();

        // Only org/school can use this
        if (!$user->isOrganization() && !$user->isSchool()) {
            return response()->json(['teachers' => []]);
        }

        // getPayableTeacherIds() already intersects the requested school with the schools
        // this account owns, so a forged school_id can never expose another school's
        // teachers. When $schoolId is empty/'all' it returns every payable teacher.
        $ids = $this->getPayableTeacherIds($user, $schoolId);

        $query = \App\User::where('role_name', \App\Models\Role::$teacher)
            ->where('status', 'active');

        if (empty($ids)) {
            $query->whereRaw('1=0');
        } else {
            $query->whereIn('id', $ids);
        }

        $teachers = $query->orderBy('full_name', 'asc')->get(['id', 'full_name', 'school_id', 'organ_id']);

        // Enrich with bank info
        $result = [];
        foreach ($teachers as $t) {
            $result[] = [
                'id' => $t->id,
                'full_name' => $t->full_name,
                'label' => $this->getInstructorBankLabel($t),
                'school_id' => $t->school_id,
                'has_bank' => !empty($t->selectedBank) && !empty($t->selectedBank->bank),
                // Dipakai client untuk membatasi input nominal custom ke saldo pengajar ini
                'payable' => $this->getInstructorSubBalance($user, $t),
            ];
        }

        return response()->json(['teachers' => $result]);
    }
}
