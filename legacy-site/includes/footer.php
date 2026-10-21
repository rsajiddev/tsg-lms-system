    <!-- Ready to Start Learning Banner (From Screenshots) -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 my-16">
        <div class="bg-gradient-to-r from-blue-600 to-blue-700 rounded-3xl p-8 sm:p-14 text-center text-white shadow-xl shadow-blue-500/15 relative overflow-hidden">
            <div class="absolute -right-10 -bottom-10 w-60 h-60 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>
            <div class="relative z-10 max-w-2xl mx-auto space-y-6">
                <h2 class="text-3xl sm:text-4xl font-extrabold tracking-tight">Ready to start learning?</h2>
                <p class="text-blue-100 text-base sm:text-lg">Create your account in a minute and pick your first course. Practical skills with verified certifications.</p>
                <div class="flex flex-col sm:flex-row justify-center items-center gap-4 pt-2">
                    <a href="register.php" class="w-full sm:w-auto bg-amber-400 hover:bg-amber-300 text-slate-950 font-bold px-7 py-3.5 rounded-xl shadow-md transition-all">Create free account</a>
                    <a href="contact.php" class="w-full sm:w-auto border-2 border-white/80 hover:bg-white hover:text-blue-700 text-white font-semibold px-7 py-3.5 rounded-xl transition-all">Talk to us</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer (From Screenshots) -->
    <footer class="bg-white border-t border-slate-200 mt-auto pt-14 pb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-10 pb-12 border-b border-slate-100">

                <!-- Brand Info -->
                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-lg bg-blue-600 flex items-center justify-center text-white font-bold text-base">SG</div>
                        <span class="text-lg font-extrabold text-slate-900">Tech SG <span class="text-blue-600">Academy</span></span>
                    </div>
                    <p class="text-sm text-slate-500 leading-relaxed">
                        Live and recorded IT training in web development, digital marketing and design, from a registered software and digital agency.
                    </p>
                    <p class="text-xs text-slate-400">
                        Operated by <strong class="text-slate-600">Tech SG Studio (SMC-PVT.) LTD.</strong>
                    </p>
                </div>

                <!-- Academy Links -->
                <div>
                    <h3 class="font-bold text-slate-900 text-sm mb-4">Academy</h3>
                    <ul class="space-y-2.5 text-sm text-slate-600">
                        <li><a href="courses.php" class="hover:text-blue-600 transition">All courses</a></li>
                        <li><a href="about.php" class="hover:text-blue-600 transition">About us</a></li>
                        <li><a href="contact.php" class="hover:text-blue-600 transition">Contact</a></li>
                        <li><a href="verify.php" class="text-blue-600 font-semibold hover:underline">Verify a certificate</a></li>
                    </ul>
                </div>

                <!-- Students -->
                <div>
                    <h3 class="font-bold text-slate-900 text-sm mb-4">Students</h3>
                    <ul class="space-y-2.5 text-sm text-slate-600">
                        <li><a href="register.php" class="hover:text-blue-600 transition">Create account</a></li>
                        <li><a href="login.php" class="hover:text-blue-600 transition">Log in</a></li>
                        <li><a href="forgot-password.php" class="hover:text-blue-600 transition">Forgot password</a></li>
                    </ul>
                </div>

                <!-- Reach Us -->
                <div>
                    <h3 class="font-bold text-slate-900 text-sm mb-4">Reach us</h3>
                    <ul class="space-y-2.5 text-sm text-slate-600">
                        <li class="flex items-center gap-2"><i class="fa-regular fa-envelope text-slate-400"></i> info@techsgstudio.com</li>
                        <li class="flex items-center gap-2"><i class="fa-solid fa-globe text-slate-400"></i> techsgstudio.com</li>
                        <li class="flex items-center gap-2"><i class="fa-regular fa-clock text-slate-400"></i> Mon to Sat, 10 AM to 6 PM</li>
                    </ul>
                </div>

            </div>

            <!-- Bottom Copyright -->
            <div class="pt-8 flex flex-col sm:flex-row justify-between items-center text-xs text-slate-500 gap-4">
                <p>© <?= date('Y') ?> Tech SG Studio (SMC-PVT.) LTD. All rights reserved.</p>
                <div class="flex gap-6">
                    <a href="terms.php" class="hover:text-slate-800">Terms</a>
                    <a href="privacy.php" class="hover:text-slate-800">Privacy</a>
                    <a href="refund.php" class="hover:text-slate-800">Refund policy</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Core Scripts -->
    <script src="assets/js/main.js"></script>
    </body>

    </html>