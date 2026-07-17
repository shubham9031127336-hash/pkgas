<?php if (!function_exists('__p')) { require_once __DIR__ . '/translations.php'; } ?>
<?php require_once __DIR__ . '/admin/business_helper.php'; $brand_cfg = getBrandConfig(); $brand_name = htmlspecialchars($brand_cfg['label']); ?>
    <footer>
        <div class="footer-top">
            <div class="footer-brand">
                <img src="Images/logo.png" alt="<?php echo $brand_name; ?>" class="logo" loading="lazy">
                <p><?php echo __p('footer.tagline'); ?></p>
                <div class="newsletter-form" style="margin-top: 2rem;">
                    <h5 style="font-size:0.85rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.75rem;"><?php echo __p('footer.newsletter_title', 'Stay Updated'); ?></h5>
                    <form class="newsletter-form-inline" onsubmit="event.preventDefault();subscribeNewsletter(this);">
                        <input type="email" name="email" placeholder="<?php echo __p('footer.newsletter_placeholder', 'Your email address'); ?>" required style="flex:1;min-width:0;padding:0.6rem 0.8rem;border:1px solid var(--border);border-radius:8px;font-size:0.85rem;">
                        <button type="submit" style="padding:0.6rem 1.2rem;background:var(--accent);color:#fff;border:none;border-radius:8px;font-weight:700;font-size:0.85rem;cursor:pointer;white-space:nowrap;"><?php echo __p('footer.newsletter_subscribe', 'Subscribe'); ?></button>
                    </form>
                    <p class="newsletter-msg" style="font-size:0.8rem;color:var(--muted);margin-top:0.5rem;display:none;"></p>
                </div>
            </div>
            <div class="footer-nav">
                <div class="footer-nav-col">
                    <h5><?php echo __p('footer.company'); ?></h5>
                    <ul>
                        <li><a href="index.php"><?php echo __p('footer.home'); ?></a></li>
                        <li><a href="index.php#about"><?php echo __p('footer.about'); ?></a></li>
                        <li><a href="index.php#network"><?php echo __p('footer.service_network'); ?></a></li>
                    </ul>
                </div>
                <div class="footer-nav-col">
                    <h5><?php echo __p('footer.products'); ?></h5>
                    <ul>
                        <li><a href="index.php#products"><?php echo __p('footer.gas_cylinders'); ?></a></li>
                        <li><a href="index.php#products"><?php echo __p('footer.refill_services'); ?></a></li>
                        <li><a href="index.php#products"><?php echo __p('footer.medical_gases'); ?></a></li>
                    </ul>
                </div>
                <div class="footer-nav-col">
                    <h5><?php echo __p('footer.contact'); ?></h5>
                    <ul>
                        <li><a href="index.php#contact"><?php echo __p('footer.get_in_touch'); ?></a></li>
                        <li><a href="tel:+91 9954440122"><?php echo __p('footer.call_us'); ?></a></li>
                        <li><a href="mailto:nandkishoremahato16@gmail.com"><?php echo __p('footer.email_support'); ?></a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            <p><?php echo __p('footer.copyright'); ?></p>
            <p><?php echo __p('footer.credit'); ?></p>
        </div>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr" defer></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var pickerInputs = document.querySelectorAll('input[type="datetime-local"], input[type="date"], input[type="time"], input[data-picker]');
        pickerInputs.forEach(function (el) {
            if (el.getAttribute('data-picker') === 'none') return;
            var inputType = el.getAttribute('type');
            var mode = el.getAttribute('data-picker') || (inputType === 'date' ? 'date' : inputType === 'time' ? 'time' : 'datetime');
            var isDateOnly = mode === 'date';
            var config = {
                allowInput: true,
                animate: true,
                position: 'auto',
                monthSelectorType: 'static',
                onChange: function (selectedDates, dateStr, inst) {
                    if (selectedDates.length) {
                        inst.input.value = inst.formatDate(selectedDates[0], inst.config.dateFormat);
                    }
                },
                onOpen: function (dates, dateStr, inst) {
                    inst._origValue = inst.input.value || '';
                },
                onKeyDown: function (_, __, ___, ____, inst) {
                    if (_.key === 'Escape') {
                        if (inst._origValue) {
                            inst.setDate(inst._origValue, true);
                        } else {
                            inst.clear();
                        }
                        inst.close();
                    }
                }
            };
            if (mode === 'date') {
                config.enableTime = false;
                config.dateFormat = 'Y-m-d';
                config.altInput = true;
                config.altFormat = 'd M Y';
                config.time_24hr = true;
                config.closeOnSelect = true;
            } else if (mode === 'datetime') {
                config.enableTime = true;
                config.dateFormat = 'Y-m-d\\TH:i';
                config.altInput = true;
                config.altFormat = 'd M Y, h:i K';
                config.time_24hr = true;
                config.closeOnSelect = false;
            } else if (mode === 'time') {
                config.enableTime = true;
                config.noCalendar = true;
                config.dateFormat = 'H:i';
                config.altInput = true;
                config.altFormat = 'h:i K';
                config.time_24hr = true;
                config.closeOnSelect = false;
            }
            if (!isDateOnly) {
                var _onReady = config.onReady || function () {};
                config.onReady = function (dates, dateStr, inst) {
                    _onReady.call(this, dates, dateStr, inst);
                    if (inst.calendarContainer.querySelector('.flatpickr-btn-bar')) return;
                    var bar = document.createElement('div');
                    bar.className = 'flatpickr-btn-bar';
                    bar.innerHTML = '<button type="button" class="fp-btn fp-cancel" data-fp-cancel>Cancel</button><button type="button" class="fp-btn fp-save" data-fp-save>Save</button>';
                    bar.querySelector('[data-fp-save]').addEventListener('click', function () {
                        var dt;
                        if (inst.selectedDates.length) {
                            dt = inst.selectedDates[0];
                        } else if (inst._origValue) {
                            dt = inst.parseDate(inst._origValue, inst.config.dateFormat);
                        } else {
                            dt = new Date();
                        }
                        inst.setDate(dt, true);
                        fireChange(inst);
                        inst.close();
                    });
                    bar.querySelector('[data-fp-cancel]').addEventListener('click', function () {
                        if (inst._origValue) {
                            inst.setDate(inst._origValue, true);
                        } else {
                            inst.clear();
                        }
                        fireChange(inst);
                        inst.close();
                    });
                    inst.calendarContainer.appendChild(bar);
                };
            }
            var fp = flatpickr(el, config);
            var form = el.closest('form');
            if (form) {
                form.addEventListener('submit', function () {
                    if (fp && fp.selectedDates.length) {
                        fp.input.value = fp.formatDate(fp.selectedDates[0], fp.config.dateFormat);
                    }
                });
            }
        });

        function fireChange(inst) {
            var evt = new Event('change', { bubbles: true });
            inst.input.dispatchEvent(evt);
        }
    });
    </script>
    <script>
    function subscribeNewsletter(form) {
        var input = form.querySelector('input[type="email"]');
        var msg = form.parentElement.querySelector('.newsletter-msg');
        var btn = form.querySelector('button');
        if (!input.value) return;
        btn.disabled = true;
        btn.textContent = '...';
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'newsletter-subscribe.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function () {
            btn.disabled = false;
            btn.textContent = 'Subscribe';
            try {
                var res = JSON.parse(xhr.responseText);
                msg.textContent = res.message;
                msg.style.display = 'block';
                msg.style.color = res.success ? 'var(--success, #10b981)' : 'var(--danger, #ef4444)';
                if (res.success) { input.value = ''; }
            } catch(e) { msg.textContent = 'Something went wrong.'; msg.style.display = 'block'; msg.style.color = 'var(--danger, #ef4444)'; }
        };
        xhr.onerror = function () {
            btn.disabled = false;
            btn.textContent = 'Subscribe';
            msg.textContent = 'Network error. Please try again.';
            msg.style.display = 'block';
            msg.style.color = 'var(--danger, #ef4444)';
        };
        xhr.send('email=' + encodeURIComponent(input.value));
    }
    </script>
</body>
</html>
