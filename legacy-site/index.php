<?php
$pageTitle = "Tech SG Academy | Live & Recorded IT Courses";
include 'includes/header.php';
?>

<!-- Hero Section with Dotted Grid and Floating Animated Elements -->
<section class="relative bg-white bg-dot-pattern pt-12 pb-24 overflow-hidden border-b border-slate-200/60">
    <!-- Interactive Canvas Particles strictly bounded in Hero -->
    <canvas id="hero-particles-canvas"></canvas>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">

            <!-- Left Hero Content -->
            <div class="lg:col-span-7 space-y-6 text-center lg:text-left">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 pulse-dot"></span>
                    Admissions open for new batches
                </div>

                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-slate-900 tracking-tight leading-[1.15]">
                    Learn IT skills live with a teacher, <span class="text-blue-600">or at your own pace</span>
                </h1>

                <p class="text-base sm:text-lg text-slate-600 max-w-2xl mx-auto lg:mx-0 leading-relaxed">
                    Practical web development, digital marketing and design courses from <strong>Tech SG Studio (SMC-PVT.) LTD.</strong> Choose live Zoom classes with recordings, or recorded lectures only.
                </p>

                <!-- Search Input Box -->
                <form action="courses.php" method="GET" class="max-w-xl mx-auto lg:mx-0 flex items-center bg-white p-1.5 rounded-2xl border-2 border-slate-200 shadow-lg shadow-slate-200/50 focus-within:border-blue-600 transition-all">
                    <div class="pl-4 text-slate-400">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </div>
                    <input type="text" name="q" placeholder="What do you want to learn?" class="w-full px-3 py-2 text-sm text-slate-800 placeholder-slate-400 focus:outline-none">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm px-6 py-3 rounded-xl transition">
                        Search
                    </button>
                </form>

                <!-- Popular Tags -->
                <div class="flex flex-wrap items-center justify-center lg:justify-start gap-2 pt-1 text-xs text-slate-500">
                    <span class="font-medium text-slate-700">Popular:</span>
                    <a href="courses.php?category=web" class="bg-white border border-slate-200 hover:border-blue-500 px-3 py-1.5 rounded-lg transition">Web Development</a>
                    <a href="courses.php?category=marketing" class="bg-white border border-slate-200 hover:border-blue-500 px-3 py-1.5 rounded-lg transition">Digital Marketing</a>
                    <a href="courses.php?category=design" class="bg-white border border-slate-200 hover:border-blue-500 px-3 py-1.5 rounded-lg transition">Graphic Design</a>
                </div>
            </div>

            <!-- Right Hero: Floating Live UI Widgets (Refined from Screenshot 4) -->
            <div class="lg:col-span-5 relative flex justify-center">
                <div class="relative w-full max-w-md h-[420px]">

                    <!-- Widget 1: Live Today Zoom Card (Main) -->
                    <div class="absolute top-2 left-0 right-8 bg-white rounded-2xl p-5 border border-slate-200 shadow-xl shadow-slate-200/80 animate-float-1 z-20">
                        <div class="flex items-center justify-between text-xs font-semibold mb-2">
                            <span class="flex items-center gap-1.5 text-rose-600 font-bold uppercase tracking-wider">
                                <span class="w-2 h-2 rounded-full bg-rose-500 pulse-dot"></span> LIVE TODAY, 7:00 PM
                            </span>
                            <span class="text-slate-400">Batch #04</span>
                        </div>
                        <h4 class="font-bold text-slate-900 text-base">Web Development</h4>
                        <p class="text-xs text-slate-500 mb-4">PHP forms and validation with mentor</p>
                        <a href="courses.php" class="inline-flex items-center justify-center w-full bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold py-2.5 rounded-xl shadow-md shadow-blue-500/20 transition">
                            <i class="fa-solid fa-video mr-2"></i> Join Zoom class
                        </a>
                    </div>

                    <!-- Widget 2: 68% Course Progress Widget -->
                    <div class="absolute top-36 -right-2 bg-white rounded-2xl p-4 border border-slate-200 shadow-xl shadow-slate-200/80 animate-float-2 z-30 flex items-center gap-4">
                        <div class="relative w-14 h-14 flex items-center justify-center">
                            <svg class="w-full h-full transform -rotate-90" viewBox="0 0 36 36">
                                <path class="text-slate-100" stroke-width="3.5" stroke="currentColor" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                <path class="text-blue-600" stroke-dasharray="68, 100" stroke-width="3.5" stroke-linecap="round" stroke="currentColor" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                            </svg>
                            <span class="absolute text-xs font-extrabold text-slate-900">68%</span>
                        </div>
                        <div>
                            <div class="text-xs font-bold text-slate-900">68%</div>
                            <div class="text-[11px] text-slate-500">Course progress</div>
                        </div>
                    </div>

                    <!-- Widget 3: Assignment Review Approved -->
                    <div class="absolute bottom-16 left-4 bg-white rounded-2xl p-3.5 border border-slate-200 shadow-xl shadow-slate-200/80 animate-float-1 z-20 flex items-center justify-between w-64">
                        <div>
                            <span class="text-xs font-bold text-slate-800">Assignment 4</span>
                            <div class="text-[11px] text-slate-500">Login page: 92 / 100</div>
                        </div>
                        <span class="bg-emerald-100 text-emerald-800 text-[10px] font-bold px-2 py-1 rounded-md">Approved</span>
                    </div>

                    <!-- Widget 4: Certificate Ready (Navy badge) -->
                    <div class="absolute bottom-2 right-2 bg-slate-900 text-white rounded-2xl p-4 shadow-2xl animate-float-2 z-30 max-w-[200px]">
                        <span class="text-[9px] font-extrabold uppercase tracking-wider text-amber-400 block mb-1">CERTIFICATE READY</span>
                        <div class="text-xs font-bold leading-tight">Download and share verified credential</div>
                    </div>

                </div>
            </div>

        </div>
    </div>
</section>

<!-- Trust Metrics Bar (From Screenshots) -->
<section class="bg-white border-b border-slate-200/70 py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 text-center sm:text-left">
            <div class="flex items-center justify-center sm:justify-start gap-2.5 text-xs font-bold text-slate-800">
                <i class="fa-solid fa-check text-emerald-600 text-sm"></i> Registered company
            </div>
            <div class="flex items-center justify-center sm:justify-start gap-2.5 text-xs font-bold text-slate-800">
                <i class="fa-solid fa-check text-emerald-600 text-sm"></i> Live Zoom classes
            </div>
            <div class="flex items-center justify-center sm:justify-start gap-2.5 text-xs font-bold text-slate-800">
                <i class="fa-solid fa-check text-emerald-600 text-sm"></i> Recorded lectures
            </div>
            <div class="flex items-center justify-center sm:justify-start gap-2.5 text-xs font-bold text-slate-800">
                <i class="fa-solid fa-check text-emerald-600 text-sm"></i> Assignments & quizzes
            </div>
            <div class="flex items-center justify-center sm:justify-start gap-2.5 text-xs font-bold text-slate-800">
                <i class="fa-solid fa-check text-emerald-600 text-sm"></i> Verified certificates
            </div>
        </div>
    </div>
</section>

<!-- Comparison Table: Live or Recorded (From Screenshot 1) -->
<section class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
    <div class="text-center space-y-3 mb-12">
        <h2 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">
            Live or recorded: pick what fits your life
        </h2>
        <p class="text-slate-500 text-base">Same curriculum. Live mode adds interactive sessions with your teacher.</p>
    </div>

    <div class="bg-white rounded-3xl border border-slate-200 shadow-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-slate-200">
                        <th class="p-5 sm:p-6 text-sm font-semibold text-slate-600 w-1/2">What you get</th>
                        <th class="p-5 sm:p-6 text-sm font-bold text-slate-900 text-center bg-slate-50/50 w-1/4">Recorded Only</th>
                        <th class="p-5 sm:p-6 text-sm font-bold text-white text-center bg-blue-600 w-1/4">Live + Recorded</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm">
                    <tr>
                        <td class="p-5 text-slate-700 font-medium">Recorded video lectures</td>
                        <td class="p-5 text-center text-emerald-600 font-bold bg-slate-50/30"><i class="fa-solid fa-check"></i></td>
                        <td class="p-5 text-center text-emerald-600 font-bold bg-blue-50/30"><i class="fa-solid fa-check"></i></td>
                    </tr>
                    <tr>
                        <td class="p-5 text-slate-700 font-medium">Course materials and resources</td>
                        <td class="p-5 text-center text-emerald-600 font-bold bg-slate-50/30"><i class="fa-solid fa-check"></i></td>
                        <td class="p-5 text-center text-emerald-600 font-bold bg-blue-50/30"><i class="fa-solid fa-check"></i></td>
                    </tr>
                    <tr>
                        <td class="p-5 text-slate-700 font-medium">Assignments with teacher feedback</td>
                        <td class="p-5 text-center text-emerald-600 font-bold bg-slate-50/30"><i class="fa-solid fa-check"></i></td>
                        <td class="p-5 text-center text-emerald-600 font-bold bg-blue-50/30"><i class="fa-solid fa-check"></i></td>
                    </tr>
                    <tr>
                        <td class="p-5 text-slate-700 font-medium">Quizzes with instant results</td>
                        <td class="p-5 text-center text-emerald-600 font-bold bg-slate-50/30"><i class="fa-solid fa-check"></i></td>
                        <td class="p-5 text-center text-emerald-600 font-bold bg-blue-50/30"><i class="fa-solid fa-check"></i></td>
                    </tr>
                    <tr>
                        <td class="p-5 text-slate-700 font-medium">Verified certificate with online link</td>
                        <td class="p-5 text-center text-emerald-600 font-bold bg-slate-50/30"><i class="fa-solid fa-check"></i></td>
                        <td class="p-5 text-center text-emerald-600 font-bold bg-blue-50/30"><i class="fa-solid fa-check"></i></td>
                    </tr>
                    <tr>
                        <td class="p-5 text-slate-700 font-medium">Live Zoom classes (interactive)</td>
                        <td class="p-5 text-center text-slate-300 font-bold bg-slate-50/30">—</td>
                        <td class="p-5 text-center text-emerald-600 font-bold bg-blue-50/30"><i class="fa-solid fa-check"></i></td>
                    </tr>
                    <tr>
                        <td class="p-5 text-slate-700 font-medium">Ask questions live in class</td>
                        <td class="p-5 text-center text-slate-300 font-bold bg-slate-50/30">—</td>
                        <td class="p-5 text-center text-emerald-600 font-bold bg-blue-50/30"><i class="fa-solid fa-check"></i></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- Popular Courses (From Screenshot 3) -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-end mb-10 gap-4">
        <div>
            <h2 class="text-3xl font-extrabold text-slate-900 tracking-tight">Popular courses</h2>
            <p class="text-slate-500 text-sm mt-1">Beginner-friendly courses, each available in two learning modes.</p>
        </div>
        <a href="courses.php" class="text-sm font-bold text-blue-600 hover:text-blue-700 flex items-center gap-1">
            View all courses <i class="fa-solid fa-arrow-right text-xs"></i>
        </a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">

        <!-- Course 1: Web Development -->
        <div class="bg-white rounded-3xl p-6 border border-slate-200 card-hover-effect flex flex-col justify-between">
            <div>
                <span class="inline-block px-3 py-1 bg-emerald-50 text-emerald-700 font-semibold text-xs rounded-full mb-4">
                    Web Development
                </span>
                <h3 class="text-xl font-bold text-slate-900 mb-2">Full Stack Web Development</h3>
                <p class="text-sm text-slate-600 mb-4 leading-relaxed">
                    HTML, CSS, JavaScript, PHP aur MySQL se real projects banana seekho.
                </p>
                <div class="text-xs text-slate-400 font-medium mb-6">
                    <i class="fa-regular fa-clock mr-1"></i> 3 Months · Beginner
                </div>

                <!-- Dual Price Box -->
                <div class="bg-slate-50 rounded-2xl p-4 space-y-2 mb-6 border border-slate-100">
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-slate-500 font-medium">Recorded Only</span>
                        <span class="font-bold text-slate-900">Rs. 5,000</span>
                    </div>
                    <div class="flex justify-between items-center text-xs border-t border-slate-200 pt-2">
                        <span class="text-blue-600 font-semibold">Live + Recorded</span>
                        <span class="font-bold text-blue-600 text-sm">Rs. 10,000</span>
                    </div>
                </div>
            </div>
            <a href="course-detail.php?id=1" class="w-full text-center bg-slate-900 hover:bg-blue-600 text-white font-semibold text-sm py-3 rounded-xl transition">
                View course details
            </a>
        </div>

        <!-- Course 2: Digital Marketing -->
        <div class="bg-white rounded-3xl p-6 border border-slate-200 card-hover-effect flex flex-col justify-between">
            <div>
                <span class="inline-block px-3 py-1 bg-amber-50 text-amber-700 font-semibold text-xs rounded-full mb-4">
                    Digital Marketing
                </span>
                <h3 class="text-xl font-bold text-slate-900 mb-2">Digital Marketing Essentials</h3>
                <p class="text-sm text-slate-600 mb-4 leading-relaxed">
                    SEO, social media marketing aur paid ad campaigns ki practical training.
                </p>
                <div class="text-xs text-slate-400 font-medium mb-6">
                    <i class="fa-regular fa-clock mr-1"></i> 2 Months · Beginner
                </div>

                <!-- Dual Price Box -->
                <div class="bg-slate-50 rounded-2xl p-4 space-y-2 mb-6 border border-slate-100">
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-slate-500 font-medium">Recorded Only</span>
                        <span class="font-bold text-slate-900">Rs. 3,000</span>
                    </div>
                    <div class="flex justify-between items-center text-xs border-t border-slate-200 pt-2">
                        <span class="text-blue-600 font-semibold">Live + Recorded</span>
                        <span class="font-bold text-blue-600 text-sm">Rs. 7,000</span>
                    </div>
                </div>
            </div>
            <a href="course-detail.php?id=2" class="w-full text-center bg-slate-900 hover:bg-blue-600 text-white font-semibold text-sm py-3 rounded-xl transition">
                View course details
            </a>
        </div>

        <!-- Course 3: Graphic Design -->
        <div class="bg-white rounded-3xl p-6 border border-slate-200 card-hover-effect flex flex-col justify-between">
            <div>
                <span class="inline-block px-3 py-1 bg-purple-50 text-purple-700 font-semibold text-xs rounded-full mb-4">
                    Graphic Design
                </span>
                <h3 class="text-xl font-bold text-slate-900 mb-2">Graphic Design Basics</h3>
                <p class="text-sm text-slate-600 mb-4 leading-relaxed">
                    Canva, Photoshop aur Illustrator se brand identity aur professional designs.
                </p>
                <div class="text-xs text-slate-400 font-medium mb-6">
                    <i class="fa-regular fa-clock mr-1"></i> 6 Weeks · Beginner
                </div>

                <!-- Dual Price Box -->
                <div class="bg-slate-50 rounded-2xl p-4 space-y-2 mb-6 border border-slate-100">
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-slate-500 font-medium">Recorded Only</span>
                        <span class="font-bold text-slate-900">Rs. 2,500</span>
                    </div>
                    <div class="flex justify-between items-center text-xs border-t border-slate-200 pt-2">
                        <span class="text-blue-600 font-semibold">Live + Recorded</span>
                        <span class="font-bold text-blue-600 text-sm">Rs. 6,000</span>
                    </div>
                </div>
            </div>
            <a href="course-detail.php?id=3" class="w-full text-center bg-slate-900 hover:bg-blue-600 text-white font-semibold text-sm py-3 rounded-xl transition">
                View course details
            </a>
        </div>

    </div>
</section>

<!-- Bento Grid: One Dashboard for Everything (From Screenshot 2) -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    <div class="mb-12">
        <h2 class="text-3xl font-extrabold text-slate-900 tracking-tight">One dashboard for everything</h2>
        <p class="text-slate-500 text-sm mt-1">Your classes, lessons, results and receipts, organised in one place.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

        <!-- Feature 1: Progress Tracking -->
        <div class="bg-white p-7 rounded-3xl border border-slate-200 shadow-sm space-y-4 md:col-span-2">
            <h3 class="font-bold text-slate-900 text-lg">Progress tracking</h3>
            <p class="text-xs text-slate-500">See lessons completed and your overall percentage, per course.</p>

            <div class="space-y-3 pt-2">
                <div>
                    <div class="flex justify-between text-xs font-semibold mb-1">
                        <span>Web Development</span>
                        <span>68%</span>
                    </div>
                    <div class="w-full h-2.5 bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full bg-blue-600 rounded-full" style="width: 68%;"></div>
                    </div>
                </div>
                <div>
                    <div class="flex justify-between text-xs font-semibold mb-1">
                        <span>Digital Marketing</span>
                        <span>42%</span>
                    </div>
                    <div class="w-full h-2.5 bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full bg-blue-600 rounded-full" style="width: 42%;"></div>
                    </div>
                </div>
                <div>
                    <div class="flex justify-between text-xs font-semibold mb-1">
                        <span>Graphic Design</span>
                        <span>15%</span>
                    </div>
                    <div class="w-full h-2.5 bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full bg-blue-600 rounded-full" style="width: 15%;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Feature 2: Class Schedule -->
        <div class="bg-white p-7 rounded-3xl border border-slate-200 shadow-sm space-y-3">
            <h3 class="font-bold text-slate-900 text-lg">Class schedule</h3>
            <p class="text-xs text-slate-500">Upcoming live classes with date, time and a one-click Zoom join.</p>
            <div class="mt-4 p-4 rounded-2xl bg-blue-50 border border-blue-100">
                <span class="text-[10px] font-bold text-blue-700 uppercase">Next Live Class</span>
                <p class="font-bold text-slate-900 text-sm mt-0.5">PHP & MySQL Databases</p>
                <p class="text-xs text-slate-500">Today at 7:00 PM</p>
            </div>
        </div>

        <!-- Feature 3: Assignments -->
        <div class="bg-white p-7 rounded-3xl border border-slate-200 shadow-sm space-y-2">
            <h3 class="font-bold text-slate-900 text-lg">Assignments</h3>
            <p class="text-xs text-slate-500">Submit GitHub links or files and read detailed teacher feedback.</p>
        </div>

        <!-- Feature 4: Timed Quizzes -->
        <div class="bg-white p-7 rounded-3xl border border-slate-200 shadow-sm space-y-2">
            <h3 class="font-bold text-slate-900 text-lg">Timed quizzes</h3>
            <p class="text-xs text-slate-500">Instant results and explanations after you submit each lesson quiz.</p>
        </div>

        <!-- Feature 5: Support Tickets -->
        <div class="bg-white p-7 rounded-3xl border border-slate-200 shadow-sm space-y-2">
            <h3 class="font-bold text-slate-900 text-lg">Support tickets</h3>
            <p class="text-xs text-slate-500">Ask your teacher or the admin team doubts and track the reply.</p>
        </div>

    </div>
</section>

<!-- Verified Certificates Section (From Screenshot 8) -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 border-t border-slate-200/60">
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">

        <!-- Left Content -->
        <div class="lg:col-span-6 space-y-6">
            <h2 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight leading-tight">
                A certificate anyone <br class="hidden sm:inline"> can verify
            </h2>
            <p class="text-slate-600 text-sm sm:text-base leading-relaxed">
                Complete the required progress, assignments and final quiz and your certificate is generated automatically with a unique verification ID.
            </p>

            <ul class="space-y-3.5 text-sm font-semibold text-slate-800">
                <li class="flex items-center gap-3">
                    <span class="w-5 h-5 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-xs"><i class="fa-solid fa-check"></i></span>
                    Unique certificate ID and verification code
                </li>
                <li class="flex items-center gap-3">
                    <span class="w-5 h-5 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-xs"><i class="fa-solid fa-check"></i></span>
                    Download or print as high-res PDF
                </li>
                <li class="flex items-center gap-3">
                    <span class="w-5 h-5 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-xs"><i class="fa-solid fa-check"></i></span>
                    Employers can verify it publicly on our website
                </li>
            </ul>

            <div class="pt-2">
                <a href="courses.php" class="bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm px-7 py-3.5 rounded-xl shadow-md transition">
                    Start a course
                </a>
            </div>
        </div>

        <!-- Right Certificate Mockup -->
        <div class="lg:col-span-6 flex justify-center">
            <div class="w-full max-w-lg bg-white p-6 sm:p-8 rounded-3xl border-2 border-slate-200 shadow-2xl relative">
                <div class="border-2 border-blue-600/30 p-6 rounded-2xl text-center space-y-3 relative overflow-hidden bg-slate-50/50">
                    <div class="text-[10px] font-bold tracking-widest text-slate-400 uppercase">TECH SG STUDIO (SMC-PVT.) LTD.</div>
                    <h4 class="text-xl font-extrabold text-slate-900 tracking-wider">CERTIFICATE OF COMPLETION</h4>
                    <p class="text-xs text-slate-500">This is to certify that</p>
                    <div class="text-2xl font-bold text-blue-700 py-1 border-b border-slate-200 inline-block px-6">
                        Student Full Name
                    </div>
                    <p class="text-xs text-slate-600 max-w-sm mx-auto">has successfully completed the Web Development Program with practical assignments and project delivery.</p>
                    <div class="pt-4 flex justify-between items-center text-[10px] text-slate-400 border-t border-slate-200">
                        <span>ID: TSG-CRS-2026-001</span>
                        <span class="text-blue-600 font-semibold">techsgstudio.com/verify</span>
                    </div>
                </div>
            </div>
        </div>

    </div>
</section>

<?php include 'includes/footer.php'; ?>