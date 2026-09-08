<!DOCTYPE html>
<html>

<head>
    <title>Back in Stock</title>
</head>

<body>
    <h2>Good News!</h2>

    <p>Dear {{ $user->full_name }},</p>

    <p>The item you were interested in is now back in stock!</p>

    @if ($variant)
        <p><strong>Product:</strong> {{ $product->name }}</p>
        <p><strong>Variant:</strong> {{ $variant->attributes }}</p>
        <p><strong>SKU:</strong> {{ $variant->sku }}</p>
        <p><strong>Available Stock:</strong> {{ $variant->stock_quantity }}</p>
    @else
        <p><strong>Product:</strong> {{ $product->name }}</p>
    @endif

    <p>
    <p>
        <a href="{{ rtrim(env('FRONTEND_URL'), '/') . '/product/' . $product->slug }}"
            style="display: inline-block; padding: 10px 20px; background-color: #4CAF50; color: white; text-decoration: none; border-radius: 5px;">
            View Product
        </a>
    </p>
    </p>

    <p>Thank you for your interest!</p>
</body>

</html>
