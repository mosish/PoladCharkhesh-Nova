<?php

declare(strict_types=1);

// Nova inquiry endpoint (D1, 05 §13): the only public PHP, a same-origin POST
// on both language hosts. The application checks the host, origin, abuse
// limits and input, stores the inquiry, then notifies. This file only locates
// the code: the repository's nova/ in development; step 15 points it at the
// deployed application version (~/app-current.php).

$appRoot = dirname(__DIR__, 2);
require $appRoot . '/vendor/autoload.php';

Nova\Inquiry\InquiryKernel::run($appRoot);
