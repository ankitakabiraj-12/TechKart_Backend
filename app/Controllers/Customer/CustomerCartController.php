<?php

declare(strict_types=1);

namespace App\Controllers\Customer;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Controller handling shopping cart operations for TechKart Customer App.
 *
 * Rules:
 * - Access is strictly restricted to authenticated customers (via AuthMiddleware(['customer'])).
 * - Always calculate prices and totals server-side using current database values.
 * - Enforce product availability (status = 'published', active seller) and stock limits.
 * - Prevent duplicate cart lines for the same product via UNIQUE(customer_id, product_id) and UPSERT/update logic.
 */
class CustomerCartController
{
    /**
     * Helper to resolve the authenticated customer ID.
     */
    private function resolveCustomerId(Request $request): int
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('Unauthorized. Authentication required.', 401);
        }

        if ($user['role'] !== 'customer') {
            Response::error('Forbidden. Only customers can access the shopping cart.', 403);
        }

        return (int)$user['id'];
    }

    /**
     * Get the full cart for the authenticated customer with recalculated totals.
     * GET /api/v1/customer/cart
     */
    public function index(Request $request): void
    {
        $customerId = $this->resolveCustomerId($request);

        try {
            $pdo = Database::getConnection();
            $cartData = $this->buildCartResponse($pdo, $customerId);

            Response::json($cartData, 200, 'Cart retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to retrieve cart: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Add an item to the customer's cart.
     * POST /api/v1/customer/cart
     * Body: { product_id: int, quantity?: int }
     *
     * Prevents duplicate lines: if the product already exists in the cart, the quantity is incremented.
     */
    public function store(Request $request): void
    {
        $customerId = $this->resolveCustomerId($request);

        $productId = (int)$request->get('product_id', 0);
        $variantId = $request->get('variant_id') ? (int)$request->get('variant_id') : null;
        $quantity = (int)$request->get('quantity', 1);

        if ($productId <= 0) {
            Response::error('Validation failed: A valid product_id is required.', 422, [
                'product_id' => 'Valid product ID is required.'
            ]);
        }

        if ($quantity <= 0) {
            Response::error('Validation failed: Quantity must be greater than zero.', 422, [
                'quantity' => 'Quantity must be at least 1.'
            ]);
        }

        try {
            $pdo = Database::getConnection();

            // 1. Fetch and validate product status, seller status, and available stock
            $productStmt = $pdo->prepare(
                'SELECT p.id, p.title, p.price, p.stock_quantity, p.status, u.is_active AS seller_active
                 FROM products p
                 JOIN users u ON p.seller_id = u.id
                 WHERE p.id = :product_id
                 LIMIT 1'
            );
            $productStmt->execute(['product_id' => $productId]);
            $product = $productStmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                Response::error('Product not found.', 404);
            }

            if ($product['status'] !== 'published' || (int)$product['seller_active'] !== 1) {
                Response::error('Product is currently unavailable for purchase.', 400);
            }

            $currentStock = (int)$product['stock_quantity'];

            // If variant is provided, check variant stock
            $listingId = null;
            if ($variantId !== null) {
                $splStmt = $pdo->prepare('SELECT id, selling_price, mrp, stock_quantity FROM seller_product_listings WHERE variant_id = :vid AND listing_status = "active" LIMIT 1');
                $splStmt->execute(['vid' => $variantId]);
                $listing = $splStmt->fetch(PDO::FETCH_ASSOC);
                if ($listing) {
                    $listingId = (int)$listing['id'];
                    $currentStock = (int)$listing['stock_quantity'];
                }
            }

            if ($currentStock <= 0) {
                Response::error('Product is currently out of stock.', 400, [
                    'available_stock' => 0
                ]);
            }

            // 2. Check if product (and variant) is already in customer's cart
            $existingSql = 'SELECT id, quantity FROM cart_items WHERE customer_id = :customer_id AND product_id = :product_id AND ' . ($variantId !== null ? 'variant_id = :variant_id' : '(variant_id IS NULL OR variant_id = 0)') . ' LIMIT 1';
            $existingStmt = $pdo->prepare($existingSql);
            $binds = [
                'customer_id' => $customerId,
                'product_id'  => $productId,
            ];
            if ($variantId !== null) {
                $binds['variant_id'] = $variantId;
            }
            $existingStmt->execute($binds);
            $existingItem = $existingStmt->fetch(PDO::FETCH_ASSOC);

            $newQuantity = $quantity;
            if ($existingItem) {
                $newQuantity = (int)$existingItem['quantity'] + $quantity;
            }

            if ($newQuantity > $currentStock) {
                $alreadyInCart = $existingItem ? (int)$existingItem['quantity'] : 0;
                $msg = $alreadyInCart > 0
                    ? "Cannot add {$quantity} more items. Only {$currentStock} items in stock (currently {$alreadyInCart} in cart)."
                    : "Cannot add {$quantity} items. Only {$currentStock} items in stock.";

                Response::error($msg, 400, [
                    'available_stock' => $currentStock,
                    'current_in_cart' => $alreadyInCart,
                    'requested_total' => $newQuantity
                ]);
            }

            // 3. Upsert cart item
            if ($existingItem) {
                $updateStmt = $pdo->prepare(
                    'UPDATE cart_items SET quantity = :quantity, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                );
                $updateStmt->execute([
                    'quantity' => $newQuantity,
                    'id'       => $existingItem['id'],
                ]);
            } else {
                $insertStmt = $pdo->prepare(
                    'INSERT INTO cart_items (customer_id, product_id, variant_id, listing_id, quantity) VALUES (:customer_id, :product_id, :variant_id, :listing_id, :quantity)'
                );
                $insertStmt->execute([
                    'customer_id' => $customerId,
                    'product_id'  => $productId,
                    'variant_id'  => $variantId,
                    'listing_id'  => $listingId,
                    'quantity'    => $newQuantity,
                ]);
            }

            $cartData = $this->buildCartResponse($pdo, $customerId);

            Response::json($cartData, 201, 'Item added to cart successfully');
        } catch (Throwable $e) {
            Response::error('Failed to add item to cart: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update quantity for a specific cart item or product.
     * PUT or PATCH /api/v1/customer/cart/{id}
     * Param: {id} can be the cart_item id OR product id.
     * Body: { quantity: int }
     *
     * Note: If quantity is set to 0, item is removed from cart.
     */
    public function update(Request $request, array $params): void
    {
        $customerId = $this->resolveCustomerId($request);
        $itemId = (int)($params['id'] ?? 0);

        if ($itemId <= 0) {
            Response::error('Invalid item ID.', 400);
        }

        if (!$request->get('quantity') && $request->get('quantity') !== 0 && $request->get('quantity') !== '0') {
            Response::error('Validation failed: quantity is required.', 422, [
                'quantity' => 'Quantity field is required.'
            ]);
        }

        $quantity = (int)$request->get('quantity');

        try {
            $pdo = Database::getConnection();

            // Locate cart item by either cart_items.id OR product_id for this customer
            $findStmt = $pdo->prepare(
                'SELECT ci.id, ci.product_id, ci.quantity, p.title, p.price, p.stock_quantity, p.status, u.is_active AS seller_active
                 FROM cart_items ci
                 JOIN products p ON ci.product_id = p.id
                 JOIN users u ON p.seller_id = u.id
                 WHERE (ci.id = :item_id OR ci.product_id = :item_id_prod) AND ci.customer_id = :customer_id
                 LIMIT 1'
            );
            $findStmt->execute([
                'item_id'       => $itemId,
                'item_id_prod'  => $itemId,
                'customer_id'   => $customerId,
            ]);
            $cartItem = $findStmt->fetch(PDO::FETCH_ASSOC);

            if (!$cartItem) {
                Response::error('Cart item not found.', 404);
            }

            // If quantity <= 0, remove item
            if ($quantity <= 0) {
                $delStmt = $pdo->prepare('DELETE FROM cart_items WHERE id = :id AND customer_id = :customer_id');
                $delStmt->execute([
                    'id'          => $cartItem['id'],
                    'customer_id' => $customerId,
                ]);

                $cartData = $this->buildCartResponse($pdo, $customerId);
                Response::json($cartData, 200, 'Item removed from cart');
            }

            // Validate product availability and stock
            if ($cartItem['status'] !== 'published' || (int)$cartItem['seller_active'] !== 1) {
                Response::error('Product is no longer available.', 400);
            }

            $currentStock = (int)$cartItem['stock_quantity'];
            if ($quantity > $currentStock) {
                Response::error("Cannot set quantity to {$quantity}. Only {$currentStock} items in stock.", 400, [
                    'available_stock' => $currentStock,
                    'requested'       => $quantity
                ]);
            }

            // Update quantity
            $updateStmt = $pdo->prepare(
                'UPDATE cart_items SET quantity = :quantity, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND customer_id = :customer_id'
            );
            $updateStmt->execute([
                'quantity'    => $quantity,
                'id'          => $cartItem['id'],
                'customer_id' => $customerId,
            ]);

            $cartData = $this->buildCartResponse($pdo, $customerId);
            Response::json($cartData, 200, 'Cart item updated successfully');
        } catch (Throwable $e) {
            Response::error('Failed to update cart item: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove an item from the customer's cart.
     * DELETE /api/v1/customer/cart/{id}
     * Param: {id} can be the cart_item id OR product id.
     */
    public function destroy(Request $request, array $params): void
    {
        $customerId = $this->resolveCustomerId($request);
        $itemId = (int)($params['id'] ?? 0);

        if ($itemId <= 0) {
            Response::error('Invalid item ID.', 400);
        }

        try {
            $pdo = Database::getConnection();

            $delStmt = $pdo->prepare(
                'DELETE FROM cart_items WHERE (id = :item_id OR product_id = :item_id_prod) AND customer_id = :customer_id'
            );
            $delStmt->execute([
                'item_id'      => $itemId,
                'item_id_prod' => $itemId,
                'customer_id'  => $customerId,
            ]);

            if ($delStmt->rowCount() === 0) {
                Response::error('Cart item not found.', 404);
            }

            $cartData = $this->buildCartResponse($pdo, $customerId);
            Response::json($cartData, 200, 'Item removed from cart successfully');
        } catch (Throwable $e) {
            Response::error('Failed to remove cart item: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Clear all items from the customer's cart.
     * DELETE /api/v1/customer/cart
     */
    public function clear(Request $request): void
    {
        $customerId = $this->resolveCustomerId($request);

        try {
            $pdo = Database::getConnection();
            $delStmt = $pdo->prepare('DELETE FROM cart_items WHERE customer_id = :customer_id');
            $delStmt->execute(['customer_id' => $customerId]);

            $cartData = $this->buildCartResponse($pdo, $customerId);
            Response::json($cartData, 200, 'Cart cleared successfully');
        } catch (Throwable $e) {
            Response::error('Failed to clear cart: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Helper to construct cart items with server-calculated pricing, stock availability,
     * seller details, primary images, and totals.
     */
    private function buildCartResponse(PDO $pdo, int $customerId): array
    {
        $sql = "SELECT
                    ci.id AS cart_item_id,
                    ci.quantity,
                    ci.variant_id,
                    ci.listing_id,
                    ci.created_at AS added_at,
                    ci.updated_at AS last_updated_at,
                    p.id AS product_id,
                    p.seller_id,
                    p.title AS product_title,
                    p.slug AS product_slug,
                    p.price AS unit_price,
                    p.mrp,
                    p.stock_quantity,
                    p.status AS product_status,
                    c.name AS category_name,
                    sp.store_name AS seller_store_name,
                    u.is_active AS seller_active,
                    pv.variant_name,
                    pv.sku AS variant_sku,
                    pv.colour AS variant_colour,
                    pv.storage AS variant_storage,
                    pv.ram AS variant_ram,
                    spl.selling_price AS variant_price,
                    spl.mrp AS variant_mrp,
                    spl.stock_quantity AS variant_stock,
                    (SELECT image_url FROM product_images WHERE product_id = p.id AND (variant_id = ci.variant_id OR is_primary = 1) ORDER BY (variant_id = ci.variant_id) DESC, is_primary DESC LIMIT 1) AS primary_image_url
                FROM cart_items ci
                JOIN products p ON ci.product_id = p.id
                JOIN users u ON p.seller_id = u.id
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN seller_profiles sp ON p.seller_id = sp.user_id
                LEFT JOIN product_variants pv ON ci.variant_id = pv.id
                LEFT JOIN seller_product_listings spl ON ci.variant_id = spl.variant_id AND spl.listing_status = 'active'
                WHERE ci.customer_id = :customer_id
                ORDER BY ci.created_at DESC, ci.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['customer_id' => $customerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        $totalItemsCount = 0;
        $subtotal = 0.0;
        $totalMrp = 0.0;
        $hasUnavailableItems = false;

        foreach ($rows as $row) {
            $qty = (int)$row['quantity'];
            $unitPrice = $row['variant_price'] !== null ? (float)$row['variant_price'] : (float)$row['unit_price'];
            $mrp = $row['variant_mrp'] !== null ? (float)$row['variant_mrp'] : ($row['mrp'] !== null ? (float)$row['mrp'] : $unitPrice);
            $stock = $row['variant_stock'] !== null ? (int)$row['variant_stock'] : (int)$row['stock_quantity'];
            $isAvailable = ($row['product_status'] === 'published') && ((int)$row['seller_active'] === 1) && ($stock > 0);
            $isQuantityAvailable = $isAvailable && ($stock >= $qty);

            if (!$isAvailable || !$isQuantityAvailable) {
                $hasUnavailableItems = true;
            }

            $lineTotal = round($unitPrice * $qty, 2);
            $lineMrpTotal = round($mrp * $qty, 2);

            $subtotal += $lineTotal;
            $totalMrp += $lineMrpTotal;
            $totalItemsCount += $qty;

            $items[] = [
                'cart_item_id'          => (int)$row['cart_item_id'],
                'product_id'            => (int)$row['product_id'],
                'variant_id'            => $row['variant_id'] ? (int)$row['variant_id'] : null,
                'listing_id'            => $row['listing_id'] ? (int)$row['listing_id'] : null,
                'variant_name'          => $row['variant_name'],
                'variant_sku'           => $row['variant_sku'],
                'variant_colour'        => $row['variant_colour'],
                'variant_storage'       => $row['variant_storage'],
                'variant_ram'           => $row['variant_ram'],
                'seller_id'             => (int)$row['seller_id'],
                'seller_store_name'     => $row['seller_store_name'] ?? 'Authorized Seller',
                'category_name'         => $row['category_name'],
                'title'                 => $row['product_title'] . (!empty($row['variant_name']) && $row['variant_name'] !== 'Standard Edition' ? ' (' . $row['variant_name'] . ')' : ''),
                'slug'                  => $row['product_slug'],
                'primary_image_url'     => $row['primary_image_url'],
                'unit_price'            => $unitPrice,
                'mrp'                   => $mrp,
                'quantity'              => $qty,
                'line_total'            => $lineTotal,
                'stock_quantity'        => $stock,
                'is_in_stock'           => $stock > 0,
                'is_available'          => $isAvailable,
                'is_quantity_available' => $isQuantityAvailable,
                'added_at'              => $row['added_at'],
            ];
        }

        $subtotal = round($subtotal, 2);
        $totalMrp = round($totalMrp, 2);
        $totalDiscount = round(max(0.0, $totalMrp - $subtotal), 2);

        return [
            'items'                 => $items,
            'summary'               => [
                'total_unique_items' => count($items),
                'total_quantity'     => $totalItemsCount,
                'subtotal'           => $subtotal,
                'total_mrp'          => $totalMrp,
                'total_discount'     => $totalDiscount,
                'currency'           => 'INR',
                'has_unavailable_items' => $hasUnavailableItems,
            ]
        ];
    }
}
