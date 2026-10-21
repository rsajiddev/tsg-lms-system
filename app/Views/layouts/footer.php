<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
?>
</main>
<footer class="site-footer">
    <div class="footer-inner">
        <div class="footer-brand"><a class="brand" href="<?= Security::escape($baseUrl) ?>/"><span class="brand-mark">TSG</span><span>Tech SG <small>Academy</small></span></a>
            <p>Practical learning from Tech SG Studio (SMC-PVT.) LTD.</p>
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
    <div class="footer-bottom"><span>© <?= date('Y') ?> Tech SG Studio (SMC-PVT.) LTD.</span><span>Learn with purpose. Build with confidence.</span></div>
</footer>
</body>

</html>