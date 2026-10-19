<?php

return [
    'node' => env('ENROLLMENT_NODE', 'node'),
    'pdfinfo' => env('ENROLLMENT_PDFINFO', 'pdfinfo'),
    'pdftoppm' => env('ENROLLMENT_PDFTOPPM', 'pdftoppm'),
    'max_identity_pages' => 4,
    // School-wide internal policy; never configured per campaign or requirement.
    'max_file_bytes' => 1000000000,
    // One final multipart submission, regardless of the number of documents.
    'max_submission_bytes' => 1000000000,
];
