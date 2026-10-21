<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
?>
</main>
<footer class="site-footer">
    <div class="footer-inner">
        <div class="footer-brand"><a class="brand" href="<?= Security::escape($baseUrl) ?>/"><span class="brand-mark">TSG</span><span>Tech SG <small>Academy</small></span></a>
            <p>Practical learning from Tech SG Studio (SMC-PVT.) LTD.</p>
            <div class="footer-social">
                <a class="social-icon si-facebook" href="https://facebook.com/techsgstudio" target="_blank" rel="noopener noreferrer" aria-label="Facebook"><svg viewBox="0 0 24 24" fill="#fff" aria-hidden="true">
                        <path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z" />
                    </svg></a>
                <a class="social-icon si-instagram" href="https://instagram.com/techsgstudio" target="_blank" rel="noopener noreferrer" aria-label="Instagram"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" aria-hidden="true">
                        <rect x="2" y="2" width="20" height="20" rx="5" />
                        <circle cx="12" cy="12" r="4" />
                        <circle cx="17.5" cy="6.5" r="1" fill="#fff" stroke="none" />
                    </svg></a>
                <a class="social-icon si-linkedin" href="https://linkedin.com/company/techsgstudio" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn"><svg viewBox="0 0 24 24" fill="#fff" aria-hidden="true">
                        <path d="M16 8a6 6 0 016 6v7h-4v-7a2 2 0 00-2-2 2 2 0 00-2 2v7h-4v-7a6 6 0 016-6zM2 9h4v12H2z" />
                        <circle cx="4" cy="4" r="2" />
                    </svg></a>
                <a class="social-icon si-whatsapp" href="https://wa.me/923069524047" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp"><svg viewBox="0 0 24 24" fill="#fff" aria-hidden="true">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                    </svg></a>
                <a class="social-icon si-youtube" href="https://youtube.com/@techsgstudio" target="_blank" rel="noopener noreferrer" aria-label="YouTube"><svg viewBox="0 0 24 24" fill="#fff" aria-hidden="true">
                        <path d="M21.8 8s-.2-1.4-.8-2c-.8-.8-1.7-.8-2-.9C16.8 5 12 5 12 5s-4.8 0-7 .1c-.4.1-1.2.1-2 .9-.6.6-.8 2-.8 2S2 9.6 2 11.2v1.5c0 1.6.2 3.2.2 3.2s.2 1.4.8 2c.8.8 1.8.8 2.3.9C6.8 19 12 19 12 19s4.8 0 7-.2c.4-.1 1.2-.1 2-.9.6-.6.8-2 .8-2s.2-1.6.2-3.2v-1.5C22 9.6 21.8 8 21.8 8zM10 15V9l6 3-6 3z" />
                    </svg></a>
            </div>
        </div>
        <div>
            <h2>Explore</h2><a href="<?= Security::escape($baseUrl) ?>/courses">Courses</a><a href="<?= Security::escape($baseUrl) ?>/about">About us</a><a href="<?= Security::escape($baseUrl) ?>/contact">Contact</a>
        </div>
        <div>
            <h2>Student access</h2><a href="<?= Security::escape($baseUrl) ?>/register">Create account</a><a href="<?= Security::escape($baseUrl) ?>/login">Sign in</a>
        </div>
        <div>
            <h2>Get in touch</h2><a href="mailto:info@techsgstudio.com">info@techsgstudio.com</a><a href="tel:+923069524047">+92 306 9524047</a><a href="https://wa.me/923069524047" target="_blank" rel="noopener noreferrer">WhatsApp us</a><a href="https://techsgstudio.com" target="_blank" rel="noopener noreferrer">techsgstudio.com</a><span>Mon–Sat, 10 AM–6 PM</span>
        </div>
    </div>
    <div class="footer-bottom"><span>© <?= date('Y') ?> Tech SG Studio (SMC-PVT.) LTD.</span>
        <nav class="footer-legal" aria-label="Legal"><a href="<?= Security::escape($baseUrl) ?>/privacy">Privacy Policy</a><a href="<?= Security::escape($baseUrl) ?>/terms">Terms &amp; Conditions</a><a href="<?= Security::escape($baseUrl) ?>/refund-policy">Refund Policy</a></nav><span>Learn with purpose. Build with confidence.</span>
    </div>
</footer>
</body>

</html>