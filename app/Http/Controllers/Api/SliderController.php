<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Slider;
use Illuminate\Http\Request;

class SliderController extends Controller
{
    public function adminIndex()
    {
        return response()->json([
            'data' => Slider::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title_ar' => [
                'required',
                'string',
                'max:255',
            ],

            'title_en' => [
                'required',
                'string',
                'max:255',
            ],

            'description_ar' => [
                'nullable',
                'string',
            ],

            'description_en' => [
                'nullable',
                'string',
            ],

            'image' => [
                'nullable',
                'string',
                'max:2048',
            ],

            'button_text_ar' => [
                'nullable',
                'string',
                'max:100',
            ],

            'button_text_en' => [
                'nullable',
                'string',
                'max:100',
            ],

            'link' => [
                'nullable',
                'string',
                'max:2048',
            ],

            'sort_order' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],
        ]);

        $slider = Slider::create([
            ...$validated,

            'sort_order' =>
                $validated['sort_order'] ?? 0,

            'is_active' =>
                $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' =>
                'Slider created successfully.',

            'data' => $slider,
        ], 201);
    }

    public function update(
        Request $request,
        Slider $slider
    ) {
        $validated = $request->validate([
            'title_ar' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'title_en' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'description_ar' => [
                'nullable',
                'string',
            ],

            'description_en' => [
                'nullable',
                'string',
            ],

            'image' => [
                'nullable',
                'string',
                'max:2048',
            ],

            'button_text_ar' => [
                'nullable',
                'string',
                'max:100',
            ],

            'button_text_en' => [
                'nullable',
                'string',
                'max:100',
            ],

            'link' => [
                'nullable',
                'string',
                'max:2048',
            ],

            'sort_order' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],
        ]);

        $slider->update($validated);

        return response()->json([
            'message' =>
                'Slider updated successfully.',

            'data' =>
                $slider->fresh(),
        ]);
    }

    public function destroy(Slider $slider)
    {
        $slider->delete();

        return response()->json([
            'message' =>
                'Slider deleted successfully.',
        ]);
    }
}
