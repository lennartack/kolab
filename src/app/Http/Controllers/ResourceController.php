<?php

namespace App\Http\Controllers;

use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceController extends Controller
{
    /**
     * Show the form for creating a new resource.
     *
     * @return JsonResponse
     */
    #[ExcludeRouteFromDocs]
    public function create()
    {
        return $this->errorResponse(404);
    }

    /**
     * Delete a resource.
     *
     * @param string $id Resource identifier
     *
     * @return JsonResponse The response
     */
    #[ExcludeRouteFromDocs]
    public function destroy($id)
    {
        return $this->errorResponse(404);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param string $id Resource identifier
     *
     * @return JsonResponse
     */
    #[ExcludeRouteFromDocs]
    public function edit($id)
    {
        return $this->errorResponse(404);
    }

    /**
     * Listing of resources belonging to the authenticated user.
     *
     * The resource entitlements billed to the current user wallet(s)
     *
     * @return JsonResponse
     */
    #[ExcludeRouteFromDocs]
    public function index()
    {
        return $this->errorResponse(404);
    }

    /**
     * Display information of a resource specified by $id.
     *
     * @param string $id the resource to show information for
     *
     * @return JsonResponse
     */
    #[ExcludeRouteFromDocs]
    public function show($id)
    {
        return $this->errorResponse(404);
    }

    /**
     * Create a new resource.
     *
     * @param Request $request the API request
     *
     * @return JsonResponse The response
     */
    #[ExcludeRouteFromDocs]
    public function store(Request $request)
    {
        return $this->errorResponse(404);
    }

    /**
     * Update a resource.
     *
     * @param Request $request the API request
     * @param string  $id      Resource identifier
     *
     * @return JsonResponse The response
     */
    #[ExcludeRouteFromDocs]
    public function update(Request $request, $id)
    {
        return $this->errorResponse(404);
    }
}
