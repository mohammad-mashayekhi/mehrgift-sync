jQuery(function ($) {
    $(document).on('change', '.mg-excluded-cat', function () {
        var $box = $(this);
        var $li = $box.closest('li');
        if (this.checked) {
            $li.find('> ul .mg-excluded-cat').prop('checked', true);
            return;
        }
        $li.find('> ul .mg-excluded-cat').prop('checked', false);
        $box.parents('li').each(function () {
            $(this).children('label').find('.mg-excluded-cat').prop('checked', false);
        });
    });

    if (typeof MehrgiftSync === 'undefined') {
        return;
    }

    var running = false;
    var offset = 0;
    var total = MehrgiftSync.cacheTotal || 0;
    var remoteIds = null;
    var bulkMode = 'full';
    var withImages = false;

    function log(msg, replace) {
        var $log = $('#mg-log');
        if (!$log.length) {
            return;
        }
        if (replace) {
            $log.val(msg || '');
        } else if (msg) {
            $log.val(($log.val() ? $log.val() + '\n' : '') + msg);
        }
        if ($log[0]) {
            $log[0].scrollTop = $log[0].scrollHeight;
        }
    }

    function post(action, data) {
        data = data || {};
        data.action = action;
        data.nonce = MehrgiftSync.nonce;
        return $.post(MehrgiftSync.ajaxurl, data);
    }

    function batchLimit(mode, images) {
        if (images || mode === 'images') {
            return MehrgiftSync.batchWithImages;
        }
        if (mode === 'price') {
            return MehrgiftSync.batchPrice;
        }
        if (mode === 'details') {
            return MehrgiftSync.batchDetails;
        }
        if (mode === 'full') {
            return Math.min(MehrgiftSync.batchDetails, MehrgiftSync.batchNoImages);
        }
        return MehrgiftSync.batchNoImages;
    }

    function batchPauseMs(mode, images) {
        if (mode === 'price' && !images) {
            return (MehrgiftSync.batchPausePrice || 0) * 1000;
        }
        if (images || mode === 'images' || mode === 'details' || mode === 'full') {
            return (MehrgiftSync.batchPauseSec || 2) * 1000;
        }
        return 0;
    }

    function setRunning(isRunning) {
        running = isRunning;
        $('#mg-sync-btn').prop('disabled', isRunning);
        $('#mg-stop-btn').prop('disabled', !isRunning);
        $('.mg-sync-selected').prop('disabled', isRunning);
    }

    function updateProgress() {
        if (!total) {
            total = offset + 100;
        }
        var pct = Math.min(100, Math.round((offset / total) * 100));
        $('#mg-progress-bar').css('width', pct + '%');
        $('#mg-progress-text').text(offset + ' / ' + total + ' (' + pct + '%)');
    }

    function finishSync(message) {
        running = false;
        setRunning(false);
        remoteIds = null;
        log(message || 'پایان.');
        updateProgress();
    }

    function processBatch() {
        if (!running) {
            return;
        }

        var payload = {
            offset: offset,
            limit: batchLimit(bulkMode, withImages),
            with_images: withImages ? 1 : 0,
            mode: bulkMode
        };

        if (remoteIds && remoteIds.length) {
            payload.remote_ids = remoteIds;
        }

        post('mehrgift_process_local_batch', payload).done(function (res) {
            if (!res.success) {
                log('ERROR: ' + res.data);
                finishSync('متوقف شد — خطا.');
                return;
            }

            var data = res.data;
            if (data.total) {
                total = data.total;
            }
            offset = data.next_offset !== undefined ? data.next_offset : (offset + (data.advanced || data.processed || 0));
            if (data.logs) {
                log(data.logs);
            }
            updateProgress();

            if (data.done || !data.processed) {
                finishSync('همگام‌سازی تمام شد.');
                return;
            }

            var pause = batchPauseMs(bulkMode, withImages);
            if (pause > 0) {
                setTimeout(processBatch, pause);
            } else {
                processBatch();
            }
        }).fail(function () {
            log('Timeout — تلاش مجدد در ۲ ثانیه...');
            setTimeout(processBatch, 2000);
        });
    }

    function startSync(options) {
        options = options || {};
        running = true;
        offset = 0;
        bulkMode = options.mode || $('#mg-bulk-mode').val() || 'full';
        withImages = !!options.withImages || ($('#mg-with-images').length && $('#mg-with-images').is(':checked'));
        remoteIds = options.remoteIds || null;
        total = remoteIds ? remoteIds.length : (MehrgiftSync.cacheTotal || 0);

        setRunning(true);
        if ($('#mg-products-progress').length) {
            $('#mg-products-progress').show();
        }
        log('شروع پردازش (' + bulkMode + ')...', true);
        updateProgress();
        processBatch();
    }

    $('#mg-fetch-btn').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('در حال دانلود...');
        $('#mg-fetch-status').text('در حال دریافت...');
        post('mehrgift_fetch_all').done(function (res) {
            if (!res.success) {
                alert(res.data);
                return;
            }
            total = res.data.count;
            MehrgiftSync.cacheTotal = total;
            var excluded = parseInt(res.data.excluded, 10) || 0;
            var statusText = 'تعداد ' + total + ' محصول برای همگام‌سازی آماده است.';
            if (excluded) {
                statusText += ' ' + excluded + ' محصول به‌خاطر دسته حذف شد.';
            }
            $('#mg-fetch-status').text(statusText).css('color', 'green');
            $('#mg-sync-area').removeClass('is-disabled');
            $btn.text('دانلود مجدد');
            log('کش دانلود شد: ' + total);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $('#mg-sync-btn').on('click', function () {
        if (!MehrgiftSync.cacheTotal && !total) {
            alert('ابتدا لیست محصولات را دانلود کنید.');
            return;
        }
        startSync();
    });

    $('#mg-stop-btn').on('click', function () {
        running = false;
        setRunning(false);
        remoteIds = null;
        post('mehrgift_sync_stop');
        log('توقف توسط کاربر.');
    });

    function getSelectedIds() {
        return $('.mg-row-check:checked').map(function () {
            return $(this).val();
        }).get();
    }

    $('#mg-select-all, #mg-select-all-head').on('change', function () {
        var checked = $(this).is(':checked');
        $('.mg-row-check').prop('checked', checked);
        $('#mg-select-all, #mg-select-all-head').prop('checked', checked);
    });

    $('.mg-sync-selected').on('click', function () {
        var ids = getSelectedIds();
        if (!ids.length) {
            alert('حداقل یک محصول انتخاب کنید.');
            return;
        }
        var action = $(this).data('action') || 'price';
        if (!confirm('همگام‌سازی ' + ids.length + ' محصول انتخاب‌شده؟')) {
            return;
        }
        startSync({
            mode: action,
            withImages: action === 'images' || action === 'full',
            remoteIds: ids
        });
    });

    $('.mg-sync-one').on('click', function () {
        var $btn = $(this);
        var remoteId = $btn.closest('tr').data('remote-id');
        var mode = $btn.data('mode');
        $btn.addClass('is-busy').prop('disabled', true);

        post('mehrgift_sync_single', { remote_id: remoteId, mode: mode }).done(function (res) {
            if (!res.success) {
                alert(res.data);
                return;
            }
            alert('انجام شد: ' + res.data.title + (res.data.product_id ? ' (#' + res.data.product_id + ')' : ''));
            location.reload();
        }).always(function () {
            $btn.removeClass('is-busy').prop('disabled', false);
        });
    });

    $('#mg-refresh-registry').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        post('mehrgift_refresh_registry').done(function (res) {
            if (res.success) {
                alert('بروزرسانی شد: ' + res.data.count);
                location.reload();
            } else {
                alert(res.data);
            }
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $('#mg-apply-preset').on('click', function () {
        var cores = $('#mg-server-cores').val() || 3;
        post('mehrgift_apply_preset', { cores: cores }).done(function (res) {
            if (res.success) {
                alert(res.data.message);
                location.reload();
            } else {
                alert(res.data || 'خطا');
            }
        });
    });
});
