<?php
if (!isset($pageTitle)) {
    $pageTitle = "Tech SG Academy | Live & Recorded IT Courses";
}
$activePage = basename($_SERVER['PHP_SELF'], ".php");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <!-- Tailwind CSS CDN for modern responsive utilities -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#0f172a'
                        }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- FontAwesome for clean icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>

<body class="bg-slate-50/50 text-slate-700 antialiased flex flex-col min-h-screen">

    <!-- Top Announcement Bar (From Screenshots) -->
    <div class="bg-slate-900 text-slate-300 text-xs py-2 px-4 border-b border-slate-800">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-2">
            <span class="font-medium text-slate-200">
                An initiative of <strong class="text-white"><a href="https://techsgstudio.com">Tech SG Studio (SMC-PVT.) LTD.</a> </strong>
            </span>
            <div class="flex items-center gap-6 text-slate-400">
                <a href="mailto:info@techsgstudio.com" class="hover:text-white transition flex items-center gap-1.5">
                    <i class="fa-regular fa-envelope"></i> info@techsgstudio.com
                </a>
                <span class="hidden md:inline text-slate-600">|</span>
                <span class="hidden md:flex items-center gap-1.5">
                    <i class="fa-regular fa-clock"></i> Mon to Sat, 10 AM to 6 PM
                </span>
            </div>
        </div>
    </div>

    <!-- Main Navigation Bar -->
    <header class="bg-white/90 backdrop-blur-md sticky top-0 z-50 border-b border-slate-200/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-20">

                <!-- Logo -->
                <a href="index.php" class="flex items-center gap-3 group">
                    <div class="w-11 h-11 rounded-xl bg-blue-600 flex items-center justify-center text-white font-extrabold text-lg shadow-md shadow-blue-500/20 group-hover:scale-105 transition-transform">
                        SG
                    </div>
                    <div>
                        <span class="text-xl font-extrabold text-slate-900 tracking-tight">Tech SG</span>
                        <span class="text-xl font-bold text-blue-600">Academy</span>
                    </div>
                </a>

                <!-- Desktop Menu Links -->
                <nav class="hidden md:flex items-center space-x-8 text-sm font-semibold text-slate-600">
                    <a href="index.php" class="<?= $activePage == 'index' ? 'text-blue-600 border-b-2 border-blue-600 pb-1' : 'hover:text-blue-600 transition' ?>">Home</a>
                    <a href="courses.php" class="<?= $activePage == 'courses' ? 'text-blue-600 border-b-2 border-blue-600 pb-1' : 'hover:text-blue-600 transition' ?>">Courses</a>
                    <a href="about.php" class="<?= $activePage == 'about' ? 'text-blue-600 border-b-2 border-blue-600 pb-1' : 'hover:text-blue-600 transition' ?>">About</a>
                    <a href="contact.php" class="<?= $activePage == 'contact' ? 'text-blue-600 border-b-2 border-blue-600 pb-1' : 'hover:text-blue-600 transition' ?>">Contact</a>
                </nav>

                <!-- Auth Buttons -->
                <div class="hidden md:flex items-center space-x-4">
                    <a href="login.php" class="text-sm font-semibold text-slate-700 hover:text-blue-600 px-3 py-2 transition">Log in</a>
                    <a href="register.php" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-5 py-2.5 rounded-xl shadow-sm hover:shadow-md hover:shadow-blue-500/20 transition-all">Get started</a>
                </div>

                <!-- Mobile Hamburger Button -->
                <button id="mobile-menu-btn" class="md:hidden text-slate-700 p-2 rounded-lg hover:bg-slate-100">
                    <i class="fa-solid fa-bars text-xl"></i>
                </button>
            </div>
        </div>

        <!-- Mobile Drawer -->
        <div id="mobile-menu" class="hidden md:hidden bg-white border-b border-slate-200 px-4 pt-3 pb-5 space-y-3">
            <a href="index.php" class="block font-medium px-3 py-2 rounded-lg <?= $activePage == 'index' ? 'bg-blue-50 text-blue-600 font-bold' : 'text-slate-700 hover:bg-slate-50' ?>">Home</a>
            <a href="courses.php" class="block font-medium px-3 py-2 rounded-lg <?= $activePage == 'courses' ? 'bg-blue-50 text-blue-600 font-bold' : 'text-slate-700 hover:bg-slate-50' ?>">Courses</a>
            <a href="about.php" class="block font-medium px-3 py-2 rounded-lg <?= $activePage == 'about' ? 'bg-blue-50 text-blue-600 font-bold' : 'text-slate-700 hover:bg-slate-50' ?>">About</a>
            <a href="contact.php" class="block font-medium px-3 py-2 rounded-lg <?= $activePage == 'contact' ? 'bg-blue-50 text-blue-600 font-bold' : 'text-slate-700 hover:bg-slate-50' ?>">Contact</a>
            <div class="pt-3 border-t border-slate-100 flex flex-col gap-2">
                <a href="login.php" class="w-full text-center py-2 text-sm font-semibold text-slate-700 border border-slate-200 rounded-lg">Log in</a>
                <a href="register.php" class="w-full text-center py-2 text-sm font-semibold bg-blue-600 text-white rounded-lg">Get started</a>
            </div>
        </div>
    </header>