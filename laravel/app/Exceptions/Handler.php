<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (PostTooLargeException $e, $request) {
            $limit = ini_get('post_max_size');
            $referer = $request->headers->get('referer');

            if ($referer) {
                $separator = str_contains($referer, '?') ? '&' : '?';
                return redirect($referer . $separator . 'upload_error=too_large&limit=' . urlencode($limit));
            }

            return response(
                "Upload too large. The current server limit is {$limit}B. Please go back and try a smaller file.",
                413
            );
        });
    }
}
