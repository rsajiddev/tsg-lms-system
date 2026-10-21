<?php
$pageTitle = "Contact Us | Tech SG Academy";
include 'includes/header.php';
?>

<div class="bg-dot-pattern border-b border-slate-200 py-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <nav class="text-xs text-slate-400 mb-3">
            <a href="index.php" class="hover:text-blue-600">Home</a> / <span class="text-slate-700">Contact</span>
        </nav>
        <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">Get in touch</h1>
        <p class="text-slate-500 text-sm mt-1">Have questions about courses or admission? Talk to our team.</p>
    </div>
</div>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-12">
        <div class="bg-white p-8 rounded-3xl border border-slate-200 shadow-sm space-y-6">
            <h3 class="text-xl font-bold text-slate-900">Send a message</h3>
            <form action="#" method="POST" class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Your Name</label>
                    <input type="text" placeholder="Muhammad Ali" class="w-full px-4 py-2.5 text-sm border border-slate-200 rounded-xl focus:outline-none focus:border-blue-600">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Email or WhatsApp</label>
                    <input type="text" placeholder="0300-1234567 or you@mail.com" class="w-full px-4 py-2.5 text-sm border border-slate-200 rounded-xl focus:outline-none focus:border-blue-600">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Question / Query</label>
                    <textarea rows="4" placeholder="I want to know about Web Development Live batch..." class="w-full px-4 py-2.5 text-sm border border-slate-200 rounded-xl focus:outline-none focus:border-blue-600"></textarea>
                </div>
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm py-3 rounded-xl transition">Submit Message</button>
            </form>
        </div>

        <div class="space-y-6">
            <div class="bg-white p-8 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                <h3 class="text-xl font-bold text-slate-900">Official Office Information</h3>
                <p class="text-sm text-slate-600">Tech SG Studio (SMC-PVT.) LTD.</p>
                <div class="space-y-3 text-sm text-slate-700 pt-2">
                    <p class="flex items-center gap-3"><i class="fa-solid fa-envelope text-blue-600"></i> info@techsgstudio.com</p>
                    <p class="flex items-center gap-3"><i class="fa-solid fa-clock text-blue-600"></i> Mon to Sat, 10:00 AM to 6:00 PM</p>
                    <p class="flex items-center gap-3"><i class="fa-solid fa-shield-halved text-emerald-600"></i> SECP Registered Software Agency</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>