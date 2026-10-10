@php
    $espaySettings = \App\Services\Espay\EspaySettings::current();
    $espayActiveMode = $espaySettings['mode'] ?? (config('espay.is_production') ? 'production' : 'sandbox');
    $espayEnvLabels = ['sandbox' => 'Sandbox (Development)', 'production' => 'Production'];
@endphp

<div class="payment-channel-credentials-card border mb-3 p-3">
    <h5 class="mb-1">Kredensial Espay SNAP</h5>
    <p class="text-muted font-12 mb-3">Nilai yang dikosongkan memakai pengaturan dari file <code>.env</code>. Private key disimpan terenkripsi.</p>

    @error('credentials')
        <div class="alert alert-danger">{{ $message }}</div>
    @enderror

    <div class="form-group">
        <label class="input-label d-block">Mode aktif</label>
        @foreach($espayEnvLabels as $env => $label)
            <div class="custom-control custom-radio custom-control-inline">
                <input type="radio" id="espayMode_{{ $env }}" name="credentials[mode]" value="{{ $env }}" class="custom-control-input" {{ $espayActiveMode === $env ? 'checked' : '' }}>
                <label class="custom-control-label" for="espayMode_{{ $env }}">{{ $label }}</label>
            </div>
        @endforeach
    </div>

    <div class="alert alert-light border font-12 mb-3">
        <strong>Pencairan dana (withdraw guru/user)</strong> diproses via <strong>Espay Disbursement</strong>:
        saldo dipotong saat diajukan (status "Diproses"), lalu selesai atau dikembalikan otomatis sesuai hasil transfer.
    </div>

    <ul class="nav nav-tabs mt-2" role="tablist">
        @foreach($espayEnvLabels as $env => $label)
            <li class="nav-item">
                <a class="nav-link {{ $env === $espayActiveMode ? 'active' : '' }}" id="espayTab_{{ $env }}" data-toggle="tab" href="#espayPane_{{ $env }}" role="tab">{{ $label }}</a>
            </li>
        @endforeach
    </ul>

    <div class="tab-content border border-top-0 p-3">
        @foreach($espayEnvLabels as $env => $label)
            @php
                $values = $espaySettings[$env] ?? [];
                $envConfig = config("espay.{$env}", []);
                $merchantPublicKey = \App\Services\Espay\EspaySettings::merchantPublicKey($env);
                $hasStoredPrivateKey = !empty($values['private_key']);
            @endphp

            <div class="tab-pane fade {{ $env === $espayActiveMode ? 'show active' : '' }}" id="espayPane_{{ $env }}" role="tabpanel">
                <div class="form-group">
                    <label>Merchant Code</label>
                    <input type="text" name="credentials[{{ $env }}][merchant_code]" class="form-control" value="{{ $values['merchant_code'] ?? '' }}"
                           placeholder="{{ !empty($envConfig['merchant_code']) ? 'Dari .env: ' . $envConfig['merchant_code'] : 'Contoh: SGWYESSISHOP' }}">
                    <small class="text-muted">Kode merchant dari tim Espay (X-PARTNER-ID, merchantId, customerNo).</small>
                </div>

                <div class="form-group">
                    <label>API Key</label>
                    <input type="text" name="credentials[{{ $env }}][api_key]" class="form-control" value="{{ $values['api_key'] ?? '' }}"
                           placeholder="{{ !empty($envConfig['api_key']) ? 'Dari .env (terisi)' : 'API key dari tim Espay' }}" autocomplete="off">
                    <small class="text-muted">Dipakai untuk Inquiry Merchant Info (daftar bank) dan subMerchantId.</small>
                </div>

                <div class="form-group">
                    <label>Signature Key</label>
                    <input type="text" name="credentials[{{ $env }}][signature_key]" class="form-control" value="{{ $values['signature_key'] ?? '' }}"
                           placeholder="{{ !empty($envConfig['signature_key']) ? 'Dari .env (terisi)' : 'Signature key dari portal Espay' }}" autocomplete="off">
                    <small class="text-muted">Hash-Based Signature untuk pembuatan Virtual Account Static Open (sendinvoice).</small>
                </div>

                <div class="form-group">
                    <label>Password Notifikasi</label>
                    <input type="text" name="credentials[{{ $env }}][password]" class="form-control" value="{{ $values['password'] ?? '' }}"
                           placeholder="{{ !empty($envConfig['password']) ? 'Dari .env (terisi)' : 'Password dari tim Espay' }}" autocomplete="off">
                    <small class="text-muted">Dicocokkan dengan parameter <code>password</code> pada Payment Notification VA (non-SNAP).</small>
                </div>

                <div class="form-group">
                    <label>Private Key Merchant</label>
                    <div class="mb-2">
                        @if($hasStoredPrivateKey)
                            <span class="badge badge-success">Tersimpan di panel</span>
                        @else
                            <span class="badge badge-secondary">Belum ada di panel{{ !empty($envConfig['private_key']) ? ' (memakai .env / file)' : '' }}</span>
                        @endif
                    </div>
                    <textarea name="credentials[{{ $env }}][private_key]" rows="4" class="form-control text-monospace font-12" autocomplete="off"
                              placeholder="{{ $hasStoredPrivateKey ? 'Kosongkan bila tidak diubah. Tempel PEM baru untuk mengganti.' : '-----BEGIN PRIVATE KEY----- ...' }}"></textarea>

                    <div class="custom-control custom-checkbox mt-2">
                        <input type="checkbox" class="custom-control-input" id="espayGenerate_{{ $env }}" name="credentials[{{ $env }}][generate_key]" value="1">
                        <label class="custom-control-label" for="espayGenerate_{{ $env }}">Generate pasangan kunci baru saat disimpan (RSA 2048)</label>
                    </div>
                    @if($hasStoredPrivateKey)
                        <div class="custom-control custom-checkbox mt-1">
                            <input type="checkbox" class="custom-control-input" id="espayRemove_{{ $env }}" name="credentials[{{ $env }}][remove_private_key]" value="1">
                            <label class="custom-control-label" for="espayRemove_{{ $env }}">Hapus private key dari panel (kembali memakai .env)</label>
                        </div>
                    @endif
                </div>

                @if(!empty($merchantPublicKey))
                    <div class="form-group">
                        <label>Public Key Merchant <small class="text-muted">(kirim ke tim Espay)</small></label>
                        <textarea rows="4" class="form-control text-monospace font-12" readonly id="espayMerchantPublic_{{ $env }}">{{ $merchantPublicKey }}</textarea>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2 js-espay-copy" data-target="espayMerchantPublic_{{ $env }}">Salin public key</button>
                    </div>
                @endif

                <hr>
                <h6 class="mb-2">Disbursement (withdraw)</h6>

                <div class="form-group">
                    <label>Partner ID Disbursement</label>
                    <input type="text" name="credentials[{{ $env }}][disbursement_partner_id]" class="form-control" value="{{ $values['disbursement_partner_id'] ?? '' }}"
                           placeholder="Kosongkan bila sama dengan Merchant Code">
                    <small class="text-muted">X-PARTNER-ID untuk layanan transfer, bila Espay memberikan kode terpisah.</small>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-8">
                        <label>Rekening Sumber Dana</label>
                        <input type="text" name="credentials[{{ $env }}][disbursement_source_account]" class="form-control" value="{{ $values['disbursement_source_account'] ?? '' }}"
                               placeholder="{{ !empty($envConfig['disbursement_source_account']) ? 'Dari .env (terisi)' : 'Default: ' . config('espay.disbursement.default_source_account') . ' (deposit Espay)' }}">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Kode Bank Sumber</label>
                        <input type="text" name="credentials[{{ $env }}][disbursement_source_bank_code]" class="form-control" value="{{ $values['disbursement_source_bank_code'] ?? '' }}"
                               placeholder="Default: {{ config('espay.disbursement.default_source_bank_code') }}" maxlength="3">
                    </div>
                </div>
                <small class="text-muted d-block mb-3">Bila bank tujuan sama dengan bank sumber dipakai Transfer Intrabank (17), selain itu BI-FAST (18).</small>

                <div class="form-group mb-0">
                    <label>Public Key Espay</label>
                    <textarea name="credentials[{{ $env }}][espay_public_key]" rows="4" class="form-control text-monospace font-12"
                              placeholder="-----BEGIN PUBLIC KEY----- ... (dari tim Espay, atau dari ASPI Dev Site saat uji Client Simulator)">{{ $values['espay_public_key'] ?? '' }}</textarea>
                    <small class="text-muted">Untuk memvalidasi signature request Inquiry & Payment dari Espay.</small>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-3">
        <label class="input-label">URL yang didaftarkan ke Espay Sandbox Portal / ASPI Client Simulator</label>
        <table class="table table-sm table-bordered mb-0 font-12">
            <tr>
                <th style="width:110px">Inquiry</th>
                <td><code>{{ url('/payments/espay/v1.0/transfer-va/inquiry') }}</code></td>
            </tr>
            <tr>
                <th>Payment</th>
                <td><code>{{ url('/payments/espay/v1.0/transfer-va/payment') }}</code></td>
            </tr>
            <tr>
                <th>Payment Notification (VA non-SNAP)</th>
                <td><code>{{ url('/payments/espay/notification') }}</code></td>
            </tr>
            <tr>
                <th>Transfer Confirmation</th>
                <td><code>{{ url('/payments/espay/v1.0/transfer/confirmation') }}</code></td>
            </tr>
            <tr>
                <th>Transfer Notification</th>
                <td><code>{{ url('/payments/espay/v1.0/transfer/notification') }}</code></td>
            </tr>
            <tr>
                <th>Return URL</th>
                <td><code>{{ url('/payments/verify/Espay') }}</code></td>
            </tr>
        </table>
    </div>
</div>

@push('scripts_bottom')
    <script>
        document.querySelectorAll('.js-espay-copy').forEach(function (button) {
            button.addEventListener('click', function () {
                var target = document.getElementById(button.getAttribute('data-target'));
                target.select();
                (navigator.clipboard ? navigator.clipboard.writeText(target.value) : Promise.resolve(document.execCommand('copy')))
                    .then(function () { button.textContent = 'Tersalin ✓'; });
            });
        });
    </script>
@endpush
