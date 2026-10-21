<?php
$pageTitle = "About Us | Tech SG Academy";
include 'includes/header.php';
?>

<div class="bg-dot-pattern border-b border-slate-200 py-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <nav class="text-xs text-slate-400 mb-3">
            <a href="index.php" class="hover:text-blue-600">Home</a> / <span class="text-slate-700">About</span>
        </nav>
        <h1 class="text-4xl sm:text-5xl font-extrabold text-slate-900 tracking-tight max-w-3xl leading-tight">
            A software agency that also trains the next generation of developers
        </h1>
        <p class="text-slate-600 text-base sm:text-lg mt-4 max-w-3xl leading-relaxed">
            Tech SG Academy is the training arm of <strong>Tech SG Studio (SMC-PVT.) LTD.</strong>, a registered company building websites, desktop applications and digital marketing campaigns for clients.
        </p>
    </div>
</div>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
    <div class="grid grid-cols-1 md:grid-cols-12 gap-12 items-start">
        <div class="md:col-span-7 space-y-6">
            <h2 class="text-3xl font-extrabold text-slate-900">Why we started the Academy</h2>
            <p class="text-slate-600 leading-relaxed">
                We kept meeting people who had watched plenty of tutorials but never shipped a real project. So we built courses around doing the work: concepts first, then practice, then a project that gets reviewed.
            </p>
            <p class="text-slate-600 leading-relaxed">
                Because we deliver client projects every week, we teach the tools and habits we actually use on the job.
            </p>
        </div>

        <div class="md:col-span-5 bg-white p-8 rounded-3xl border border-slate-200 shadow-sm space-y-4">
            <h3 class="text-xl font-bold text-slate-900">What Tech SG Studio does</h3>
            <ul class="space-y-3 text-sm font-semibold text-slate-700">
                <li class="flex items-center gap-3"><i class="fa-solid fa-check text-emerald-600"></i> Web development</li>
                <li class="flex items-center gap-3"><i class="fa-solid fa-check text-emerald-600"></i> Desktop applications</li>
                <li class="flex items-center gap-3"><i class="fa-solid fa-check text-emerald-600"></i> Digital marketing</li>
                <li class="flex items-center gap-3"><i class="fa-solid fa-check text-emerald-600"></i> IT training and internships</li>
            </ul>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>