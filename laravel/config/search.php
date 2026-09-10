<?php

return [
    'pdf_result_limit' => max(1, (int) env('SEARCH_PDF_RESULT_LIMIT', 500)),
];
