<?php

$legalPage = [
    'title' => 'Privacy Policy',
    'summary' => 'This policy explains how Tech SG Studio collects, uses and protects personal information when you use its website and services.',
    'updated' => '26 October 2026',
    'source' => 'https://techsgstudio.com/privacy-policy',
    'sections' => [
        [
            'title' => 'Information We Collect',
            'points' => [
                ['label' => 'Personal information', 'text' => 'When you contact us, place an order, or submit a form, we may collect your name, email address, phone number, and WhatsApp number.'],
                ['label' => 'Payment information', 'text' => 'We collect transaction IDs, payment method details, and payment screenshots for verification purposes. We do not store credit/debit card numbers.'],
                ['label' => 'Project information', 'text' => 'Details about your project requirements, preferences, and business needs that you share with us.'],
                ['label' => 'Usage data', 'text' => 'We may collect basic information about how you interact with our website, including pages visited and time spent.'],
                ['label' => 'Communication data', 'text' => 'Messages, emails, and WhatsApp conversations you have with our team.'],
            ],
        ],
        [
            'title' => 'How We Use Your Information',
            'points' => [
                'To provide and deliver our services (website development, software, apps, digital marketing, etc.).',
                'To process payments and verify transactions.',
                'To communicate with you about your project, orders, and support requests.',
                'To send you updates, offers, and newsletters (only if you have opted in).',
                'To improve our website, services, and customer experience.',
                'To comply with legal obligations and resolve disputes.',
            ],
        ],
        [
            'title' => 'How We Protect Your Information',
            'points' => [
                'Your data is stored securely on our hosting servers with restricted access.',
                'We use SSL encryption on our website to protect data transmitted between your browser and our server.',
                'Payment proofs and sensitive files are stored in protected directories not accessible to the public.',
                'Only authorized team members have access to client information.',
                'We do not sell, rent, or trade your personal information to third parties.',
            ],
        ],
        [
            'title' => 'Information Sharing',
            'paragraphs' => ['We do not share your personal information with third parties except in the following cases:'],
            'points' => [
                ['label' => 'Service delivery', 'text' => 'If a third-party tool or platform is required to deliver your service (e.g., domain registrar, hosting provider), only necessary information is shared.'],
                ['label' => 'Legal requirements', 'text' => 'We may disclose information if required by law or legal process.'],
                ['label' => 'Business protection', 'text' => 'To protect the rights, property, or safety of Tech SG Studio, our clients, or others.'],
            ],
        ],
        [
            'title' => 'Cookies',
            'points' => [
                'Our website may use cookies to improve your browsing experience. Cookies are small files stored on your device.',
                'We use session cookies to maintain your preferences during your visit.',
                'We use analytics-related cookies to understand how visitors use our website.',
                'You can choose to disable cookies through your browser settings, but this may affect some functionality of our website.',
            ],
        ],
        [
            'title' => 'Your Rights',
            'points' => [
                ['label' => 'Access', 'text' => 'You have the right to request a copy of the personal information we hold about you.'],
                ['label' => 'Correction', 'text' => 'You can request that we correct any inaccurate information.'],
                ['label' => 'Deletion', 'text' => 'You can request that we delete your personal data, subject to legal obligations.'],
                ['label' => 'Opt out', 'text' => 'You can unsubscribe from our newsletter or marketing communications at any time.'],
            ],
            'after' => 'To exercise any of these rights, please contact us via WhatsApp or email.',
        ],
        [
            'title' => 'Third-Party Links',
            'points' => [
                'Our website may contain links to external websites. We are not responsible for the privacy practices or content of those websites.',
                'We recommend reviewing the privacy policy of any third-party website you visit through links on our website.',
            ],
        ],
        [
            'title' => "Children's Privacy",
            'points' => [
                'Our services are not directed to children under the age of 13.',
                'We do not knowingly collect personal information from children under 13.',
                'If you believe we have inadvertently collected such information, please contact us immediately.',
            ],
        ],
        [
            'title' => 'Changes to This Policy',
            'points' => [
                'We may update this Privacy Policy from time to time to reflect changes in our practices or legal requirements.',
                'We will notify you of significant changes by posting the updated policy on this page with a revised date.',
                'Your continued use of our website after changes are made constitutes your acceptance of the updated policy.',
            ],
        ],
        [
            'title' => 'Contact Us',
            'paragraphs' => ['If you have any questions, concerns, or requests regarding this Privacy Policy, please contact us:'],
            'points' => [
                ['label' => 'Phone', 'text' => '+92 306 9524047'],
                ['label' => 'WhatsApp', 'text' => '+92 306 9524047'],
                ['label' => 'Email', 'text' => 'info@techsgstudio.com'],
                ['label' => 'Website', 'text' => 'techsgstudio.com'],
            ],
        ],
    ],
    'closing' => 'This Privacy Policy is effective as of 26 October 2026.',
];

require BASE_PATH . '/app/Views/components/legal-document.php';
