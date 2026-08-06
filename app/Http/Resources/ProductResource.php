<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'product_code' => $this->product_code,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'specification' => $this->specification,
            'category_id' => $this->category_id,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'tax_category_id' => $this->tax_category_id,
            // 'tax_category' => new TaxCategoryResource($this->whenLoaded('taxCategory')),
            'retail_price' => $this->retail_price,
            'retail_price_formatted' => number_format($this->retail_price, 2),
            'distributor_price' => $this->distributor_price,
            'distributor_price_formatted' => $this->distributor_price ? number_format($this->distributor_price, 2) : null,
            'stock_quantity' => (int) $this->stock_quantity,
            'low_stock_threshold' => (int) $this->low_stock_threshold,
            'is_published' => (bool) $this->is_published,
            'status' => $this->status,
            'images' => $this->images,
            'image_urls' => $this->image_urls,
            'product_images' => ProductImageResource::collection($this->whenLoaded('images')),
            'primary_image' => new ProductImageResource($this->whenLoaded('primaryImage')),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
