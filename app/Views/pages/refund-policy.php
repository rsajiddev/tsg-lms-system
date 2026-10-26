<?php

$legalPage = [
    'title' => 'Refund Policy',
    'summary' => 'This policy explains when a refund may be available, how to request one, and how an approved refund is paid.',
    'updated' => '19 October 2026',
    'source' => 'https://techsgstudio.com/refund-policy',
    'highlights' => [
        ['value' => '100%', 'label' => 'Refund if you cancel before work starts'],
        ['value' => '7 days', 'label' => 'Window to raise a refund request'],
        ['value' => '5–7 days', 'label' => 'Working days to pay an approved refund'],
    ],
    'sections' => [
        [
            'title' => 'Overview',
            'paragraphs' => [
                'This policy applies to all services and products sold by Tech SG Studio, including websites, software, mobile apps, design, SEO, ads management, courses and digital products.',
                'Most of our work is custom and starts as soon as payment is confirmed, so refunds depend on how much work has been done. The sections below explain exactly how this works.',
            ],
        ],
        [
            'title' => 'When You Are Eligible for a Refund',
            'points' => [
                ['label' => 'You cancel before work starts', 'text' => 'You receive a full refund of the amount paid.'],
                ['label' => 'We cannot deliver', 'text' => 'We are unable to deliver the agreed scope after reasonable attempts.'],
                ['label' => 'Duplicate or accidental payment', 'text' => 'You paid twice or paid the wrong amount.'],
                ['label' => 'Work does not match the agreement', 'text' => 'The delivered work is significantly different from the written requirements and we could not fix it within the agreed revisions.'],
            ],
        ],
        [
            'title' => 'Refunds by Project Stage',
            'points' => [
                ['label' => 'Before work starts', 'text' => 'Full refund of the amount paid.'],
                ['label' => 'Planning or design in progress', 'text' => 'Refund of the amount paid minus the value of work already completed.'],
                ['label' => 'Development in progress', 'text' => 'Partial refund based on work completed and costs already incurred (domain, hosting, licences).'],
                ['label' => 'After delivery or go-live', 'text' => 'No refund. Defects covered by the agreement will be fixed free of charge.'],
            ],
        ],
        [
            'title' => 'What Is Not Refundable',
            'points' => [
                'Domains, hosting, themes, plugins and other third-party items bought on your behalf.',
                'Ad budgets already spent on Meta, Google, TikTok or other platforms.',
                'Work that was delivered and approved by you.',
                'Digital products, templates and courses once access or download has been given.',
                'Change of mind after delivery.',
                'Delays or poor results caused by missing content, feedback or approvals from the client.',
            ],
        ],
        [
            'title' => 'How to Request a Refund',
            'points' => [
                'Contact us on WhatsApp or email within 7 days of the issue.',
                'Share your tracking ID or order ID and the reason for the request.',
                'Add screenshots or files that help us understand the problem.',
                'We review every request within 2 working days and reply with our decision.',
                'We may first offer a fix or revision. If that does not solve the problem, we continue with the refund.',
            ],
        ],
        [
            'title' => 'How Refunds Are Paid',
            'points' => [
                'Refunds go back to the same method you used to pay, where possible (bank transfer or mobile wallet).',
                'Approved refunds are paid within 5–7 working days.',
                'Bank or wallet transfer fees may be deducted from the refund amount.',
                'You will receive a confirmation message once the refund is sent.',
            ],
        ],
        [
            'title' => 'If You Disagree with Our Decision',
            'paragraphs' => ['If you are not satisfied with the outcome, reply to our decision and ask for a second review. A senior team member will look at the case again and respond within 3 working days. We aim to settle every issue fairly and directly.'],
        ],
        [
            'title' => 'Changes to This Policy',
            'paragraphs' => ['We may update this policy from time to time. The latest version is always on this page with the date it was updated. Orders are covered by the policy that was published when the order was placed.'],
        ],
    ],
    'closing' => 'Need help with a refund? Send us your tracking or order ID and we will reply within 2 working days.',
];

require BASE_PATH . '/app/Views/components/legal-document.php';
