/**
 * Checkout Espay dalam popup (keranjang & top up saldo) atau halaman dashboard.
 *  - EspayCheckout.open(checkoutUrl) : buka popup dan muat pilihan metode
 *  - EspayCheckout.mount($container) : pakai di halaman dashboard (tanpa popup)
 * Setelah lunas, user diarahkan ke halaman status di dashboard (/panel/...).
 */
(function ($) {
    "use strict";

    var pollTimer = null;
    var $host = null;

    function stopPolling() {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function ajaxError(xhr, fallback) {
        var json = xhr && xhr.responseJSON;
        if (json) {
            if (json.message) return json.message;
            if (json.errors) {
                var first = Object.keys(json.errors)[0];
                if (first) return json.errors[first][0];
            }
        }
        return fallback || 'Terjadi kesalahan. Silakan coba lagi.';
    }

    function render(html) {
        stopPolling();
        $host.html(html);

        var $root = $host.find('.js-espay-pay-root');
        if ($root.data('waiting') === 1 || $root.data('waiting') === '1') {
            schedulePoll($root, 6000);
        }
    }

    function showError(message) {
        var $error = $host.find('.js-espay-error');
        if ($error.length) {
            $error.text(message).removeClass('d-none');
        } else {
            $host.html('<div class="espay-pay__error">' + $('<div>').text(message).html() + '</div>');
        }
    }

    function load(url) {
        stopPolling();
        $host.html('<div class="espay-modal__loading"><span class="espay-pay__spinner d-inline-block"></span> Memuat metode pembayaran&hellip;</div>');

        $.ajax({url: url, method: 'GET', dataType: 'json', headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .done(function (res) {
                if (res && res.redirect) {
                    window.location.href = res.redirect;
                    return;
                }
                render(res.html || '');
            })
            .fail(function (xhr) {
                showError(ajaxError(xhr, 'Gagal memuat metode pembayaran. Silakan coba lagi.'));
            });
    }

    function schedulePoll($root, delay) {
        stopPolling();
        pollTimer = setTimeout(function () {
            $.ajax({url: $root.data('status-url'), method: 'GET', dataType: 'json', headers: {'X-Requested-With': 'XMLHttpRequest'}})
                .done(function (res) {
                    if (res && res.redirect) {
                        $root.find('.js-espay-status').addClass('is-paid').text(res.status === 'paid' ? '✓ Pembayaran diterima. Mengalihkan ke dashboard…' : 'Status pembayaran berubah. Mengalihkan…');
                        setTimeout(function () { window.location.href = res.redirect; }, 1200);
                        return;
                    }
                    schedulePoll($root, 8000);
                })
                .fail(function () { schedulePoll($root, 15000); });
        }, delay);
    }

    function bindEvents() {
        $(document)
            .off('.espay')
            .on('submit.espay', '.js-espay-pay-form', function (e) {
                if (!$host || !$.contains($host[0], this)) return;
                e.preventDefault();

                var $form = $(this);
                $host.find('.espay-method').prop('disabled', true);
                $form.find('.espay-method__hint').text('Memproses…');

                $.ajax({url: $form.attr('action'), method: 'POST', data: $form.serialize(), dataType: 'json', headers: {'X-Requested-With': 'XMLHttpRequest'}})
                    .done(function (res) {
                        if (res && res.redirect) {
                            window.location.href = res.redirect;
                            return;
                        }
                        if (res && res.html) {
                            render(res.html);
                            return;
                        }
                        showError((res && res.message) || 'Gagal membuat transaksi.');
                        $host.find('.espay-method').prop('disabled', false);
                    })
                    .fail(function (xhr) {
                        showError(ajaxError(xhr, 'Gagal membuat transaksi Espay. Silakan pilih metode lain.'));
                        $host.find('.espay-method').prop('disabled', false);
                        $form.find('.espay-method__hint').text('Coba lagi');
                    });
            })
            .on('click.espay', '.js-espay-toggle-methods', function () {
                $host.find('.js-espay-methods').toggleClass('d-none');
            })
            .on('click.espay', '.js-espay-copy', function () {
                var $btn = $(this);
                var text = String($btn.data('copy'));
                var done = function () {
                    $btn.addClass('is-copied').text('Tersalin');
                    setTimeout(function () { $btn.removeClass('is-copied').text('Salin'); }, 1800);
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(done, function () { window.prompt('Salin:', text); });
                } else {
                    window.prompt('Salin:', text);
                }
            });
    }

    window.EspayCheckout = {
        open: function (checkoutUrl) {
            var $modal = $('#espayPaymentModal');
            $host = $modal.find('.js-espay-modal-body');
            bindEvents();
            $modal.off('hidden.bs.modal.espay').on('hidden.bs.modal.espay', stopPolling);
            // di atas header situs/dashboard yang z-index-nya tinggi
            $modal.modal({backdrop: 'static', keyboard: true, show: true});
            $('.modal-backdrop').last().addClass('espay-modal-backdrop');
            load(checkoutUrl);
        },

        mount: function ($container) {
            $host = $container;
            bindEvents();
            var $root = $host.find('.js-espay-pay-root');
            if ($root.data('waiting') === 1 || $root.data('waiting') === '1') {
                schedulePoll($root, 6000);
            }
        },

        /**
         * Respons AJAX payment-request: buka popup bila gateway Espay. Mengembalikan true bila ditangani.
         */
        handlePaymentResponse: function (res) {
            if (res && res.popup === 'espay' && res.checkout_url) {
                window.EspayCheckout.open(res.checkout_url);
                return true;
            }
            return false;
        },

        ajaxError: ajaxError
    };
})(jQuery);
