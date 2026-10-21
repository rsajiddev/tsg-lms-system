<?php
$pageTitle = "All IT Courses | Tech SG Academy";
include 'includes/header.php';
?>

<div class="bg-dot-pattern border-b border-slate-200 py-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <nav class="text-xs text-slate-400 mb-3">
            <a href="index.php" class="hover:text-blue-600">Home</a> / <span class="text-slate-700">Courses</span>
        </nav>
        <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">All courses</h1>
        <p class="text-slate-500 text-sm mt-1 max-w-2xl">
            Pick a course, then choose Live + Recorded or Recorded Only when you enrol. Every course ends with a verified certificate.
        </p>

        <!-- Search and Filter Bar (From Screenshot 5) -->
        <div class="mt-8 bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex flex-col md:flex-row gap-4 items-center justify-between">
            <div class="w-full md:w-1/2 relative">
                <i class="fa-solid fa-magnifying-glass absolute left-4 top-3.5 text-slate-400 text-sm"></i>
                <input type="text" placeholder="Search courses..." class="w-full pl-10 pr-4 py-2 text-sm border border-slate-200 rounded-xl focus:outline-none focus:border-blue-600">
            </div>
            <div class="w-full md:w-auto flex items-center gap-3">
                <select class="px-4 py-2 text-sm border border-slate-200 rounded-xl bg-white text-slate-700 focus:outline-none focus:border-blue-600">
                    <option>All levels</option>
                    <option>Beginner</option>
                    <option>Intermediate</option>
                </select>
                <select class="px-4 py-2 text-sm border border-slate-200 rounded-xl bg-white text-slate-700 focus:outline-none focus:border-blue-600">
                    <option>Most popular</option>
                    <option>Price: Low to High</option>
                    <option>Newest</option>
                </select>
                <button class="bg-blue-600 text-white text-sm font-semibold px-5 py-2 rounded-xl hover:bg-blue-700 transition">Apply</button>
            </div>
        </div>

        <!-- Filter Pills -->
        <div class="flex flex-wrap gap-2 mt-6 text-xs font-semibold">
            <button class="px-4 py-2 rounded-xl bg-slate-900 text-white">All (3)</button>
            <button class="px-4 py-2 rounded-xl bg-white border border-slate-200 hover:border-blue-600 text-slate-700">Digital Marketing (1)</button>
            <button class="px-4 py-2 rounded-xl bg-white border border-slate-200 hover:border-blue-600 text-slate-700">Graphic Design (1)</button>
            <button class="px-4 py-2 rounded-xl bg-white border border-slate-200 hover:border-blue-600 text-slate-700">Web Development (1)</button>
        </div>
    </div>
</div>

<!-- Courses Grid -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
        <!-- Reusable Course Cards -->
        <?php
        $courses = [
            [
                'category' => 'Web Development',
                'cat_color' => 'bg-emerald-50 text-emerald-700',
                'title' => 'Full Stack Web Development',
                'desc' => 'HTML, CSS, JavaScript, PHP aur MySQL se real projects banana seekho.',
                'meta' => '3 Months · Beginner',
                'price_rec' => 'Rs. 5,000',
                'price_live' => 'Rs. 10,000'
            ],
            [
                'category' => 'Digital Marketing',
                'cat_color' => 'bg-amber-50 text-amber-700',
                'title' => 'Digital Marketing Essentials',
                'desc' => 'SEO, social media aur ads ki practical training for clients & business.',
                'meta' => '2 Months · Beginner',
                'price_rec' => 'Rs. 3,000',
                'price_live' => 'Rs. 7,000'
            ],
            [
                'category' => 'Graphic Design',
                'cat_color' => 'bg-purple-50 text-purple-700',
                'title' => 'Graphic Design Basics',
                'desc' => 'Canva aur Photoshop se professional designs aur portfolio banana seekho.',
                'meta' => '6 Weeks · Beginner',
                'price_rec' => 'Rs. 2,500',
                'price_live' => 'Rs. 6,000'
            ]
        ];

        foreach ($courses as $c):
        ?>
        <div class="bg-white rounded-3xl p-6 border border-slate-200 card-hover-effect flex flex-col justify-between">
            <div>
                <div class="flex justify-between items-center mb-4">
                    <span class="inline-block px-3 py-1 <?= $c['cat_color'] ?> font-semibold text-xs rounded-full">
                        <?= $c['category'] ?>
                    </span>
                    <span class="text-xs bg-amber-50 text-amber-800 font-bold px-2 py-0.5 rounded">Popular</span>
                </div>
                <h3 class="text-xl font-bold text-slate-900 mb-2"><?= $c['title'] ?></h3>
                <p class="text-sm text-slate-600 mb-4"><?= $c['desc'] ?></p>
                <div class="text-xs text-slate-400 font-medium mb-6">
                    <i class="fa-regular fa-clock mr-1"></i> <?= $c['meta'] ?>
                </div>

                <div class="bg-slate-50 rounded-2xl p-4 space-y-2 mb-6 border border-slate-100">
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-slate-500 font-medium">Recorded Only</span>
                        <span class="font-bold text-slate-900"><?= $c['price_rec'] ?></span>
                    </div>
                    <div class="flex justify-between items-center text-xs border-t border-slate-200 pt-2">
                        <span class="text-blue-600 font-semibold">Live + Recorded</span>
                        <span class="font-bold text-blue-600 text-sm"><?= $c['price_live'] ?></span>
                    </div>
                </div>
            </div>
            <a href="register.php" class="w-full text-center bg-slate-900 hover:bg-blue-600 text-white font-semibold text-sm py-3 rounded-xl transition">
                View course details
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>