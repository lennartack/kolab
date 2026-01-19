<?php

namespace App\Http\Controllers;

use App\Support\Facades\Theme;
use App\Utils;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class ContentController extends Controller
{
    /**
     * Get the HTML content for the specified page
     *
     * @param string $page Page template identifier
     *
     * @return View
     */
    public function pageContent(string $page)
    {
        if (empty($page) || !preg_match('/^[a-zA-Z0-9\/]+$/', $page)) {
            abort(404);
        }

        $view = Theme::pageView($page);

        if (!$view) {
            abort(404);
        }

        return view($view)
            ->with('env', Utils::uiEnv())
            ->with('title', Theme::title())
            ->with('meta', Theme::meta());
    }

    /**
     * Get the list of FAQ entries for the specified page
     *
     * @param string $page Page path
     */
    public function faqContent(string $page): JsonResponse
    {
        if (empty($page)) {
            return $this->errorResponse(404);
        }

        $faq = Theme::faq($page);

        // Localization
        foreach ($faq as $idx => $item) {
            if (!empty($item['label'])) {
                $faq[$idx]['title'] = \trans('theme::faq.' . $item['label']);
            }
        }

        return response()->json(['status' => 'success', 'faq' => $faq]);
    }
}
