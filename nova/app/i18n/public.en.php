<?php

declare(strict_types=1);

// Production strings (en), applied on top of the verbatim Phase 2 port (phase2.en.php).
// - Prototype-only wording is replaced (no "prototype"/"sample" claims on production pages).
// - New wording for the D20/E-2 match categories; the T-1 strings are kept unchanged.
// - Product page, privacy and 404 strings. The privacy text states only approved behaviour
//   (C4 no tracking, E-12 retention, 05 §13 rate limiting) and is for owner review.

return [
    'page' => [
        'dev_banner' => 'Development build · labelled sample data, never published',
        'fam_count' => '{n} products', 'fam_count_one' => '1 product',
        'skip_content' => 'Skip to content',
        'varies' => 'varies',
        'pp_back' => 'Product explorer',
        'pp_items_h' => 'Listed items',
        'pp_items_none' => 'No item of {code} is listed at the moment. Ask a specialist about availability or an equivalent.',
        'pp_about' => 'About this product',
        'pp_family' => 'Family',
        'pp_status' => 'Data status',
        'pp_ask' => 'Ask about {code}',
        'pp_title' => '{code} — {family} | Polad Charkhesh',
        'pp_desc' => '{code} ({family}): sourced technical data and drawing; availability by consultation.',
        'pp_desc_items' => '{code} ({family}), listed as {items}: sourced technical data and drawing; availability by consultation.',
        'f_sending' => 'Sending…',
        'f_ok' => 'Your inquiry was received. A specialist will reply by phone or WhatsApp.',
        'f_err' => 'The inquiry could not be sent. Please try again, or use WhatsApp or the phone numbers on this page.',
        'nf_title' => 'Page not found | Polad Charkhesh',
        'nf_h' => 'This page doesn\'t exist',
        'nf_p' => 'The address may be mistyped, or the page has moved. Search by part code instead.',
        'nf_home' => 'Go to the product explorer',
        'pv_link' => 'Privacy',
        'pv_title' => 'Privacy | Polad Charkhesh',
        'pv_h' => 'Privacy',
        'pv_paras' => [
            'This website uses no analytics, advertising or tracking cookies, and builds no visitor profiles.',
            'When you send an inquiry, we store what you entered (name, phone number, part code, quantity and message) and the time it was sent, so that we can answer you.',
            'Inquiry details are used only to answer your request. They are never published or sold. Inquiries are kept for 24 months and then deleted.',
            'To protect the form from abuse, a one-way hash of your network address is kept for 24 hours.',
            'To correct or delete an inquiry you sent, contact us using the details on this website.',
        ],
    ],
    'js' => [
        'checked_t' => 'checked {date}',
        'prov_note' => 'Each value shows its own source and check date. A source does not state which brand is supplied.',
        'items_h' => 'Listed items',
        'items_none' => 'No item of {code} is listed at the moment. Ask a specialist about availability or an equivalent.',
        'item_values_h' => 'Values for {code}',
        'no_values' => 'No sourced values are recorded for {code} yet.',
        'varies' => 'varies',
        'spelling_note' => 'Requested: {q} — the catalogue writes it as {code}.',
        'wa_spelling' => 'Hello, availability and specifications for: {req} (catalogue: {code})',
        'f_ctx_spelling' => ' — the catalogue writes it as {code}',
        'unlisted_t' => 'Known variant, not currently listed — availability requires confirmation',
        'unlisted_p' => '“{q}” is a known variant of {product}, but it is not listed at the moment. A specialist can confirm its availability or an equivalent.',
        'ws_match_unlisted' => 'Requested: {req} · a known variant of {product}, not currently listed — availability requires confirmation',
        'wa_unlisted' => 'Hello, availability and specifications for: {req} (a known variant of {product}, not currently listed)',
        'f_ctx_unlisted' => ' — a known variant of {product}, not currently listed',
        'related_p_many' => '“{q}” is not listed on its own. The catalogue lists {listed}, recorded variants of {q}. The values shown are for the item you select; whether {q} without a suffix is available is confirmed by a specialist.',
        'related_p_none' => '“{q}” is not listed on its own, and none of its variants is listed at the moment. A specialist can confirm availability or an equivalent.',
        'ws_match_rel_none' => 'Requested: {req} · no item of {req} is listed at the moment — requested designation requires confirmation',
        'wa_related_none' => 'Hello, availability and specifications for: {req} (no item listed at the moment; to be confirmed)',
        'f_ctx_rel_none' => ' — no item listed at the moment (to be confirmed)',
        'series_p' => '“{q}” is not listed. It matches the series {base} by an approved suffix rule; the listed items of {base} are shown. Suffix details are confirmed by a specialist.',
        'list_sep' => ', ',
        'tabs' => ['Overview', 'Specifications', 'Documents'],
        'rating_p' => 'Load ratings and speeds are manufacturer-specific. They are shown only once recorded from a verified source for the supplied brand.',
    ],
];
