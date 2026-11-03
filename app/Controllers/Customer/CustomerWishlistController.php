<?php

declare(strict_types=1);

namespace App\Controllers\Customer;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Controller handling Wishlist / Saved Items operations for TechKart Customer App.
 *
 * REST API Endpoints:
 * - GET    /api/v1/customer/wishlist        List customer's saved items with product & inventory details
 * - POST   /api/v1/customer/wishlist        Add product to wishlist (body: { product_id: int })
 * - DELETE /api/v1/customer/wishlist/{id}   Remove product from wishlist ({id} = wishlist_item_id or product_id)
 * - DELETE /api/v1/customer/wishlist        Clear entire customer wishlist
 */
class CustomerWishlistController
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
            Response::error('Forbidden. Only customers can access the wishlist.', 403);
        }

        return (int)$user['id'];
    }

    /**
     * Get all wishlist items for the authenticated customer.
     * GET /api/v1/customer/wishlist
     */
    public function index(Request $request): void
    {
        $customerId = $this->resolveCustomerId($request);

        try {
            $pdo = Database::getConnection();
            $data = $this->buildWishlistResponse($pdo, $customerId);

            Response::json($data, 200, 'Wishlist retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to retrieve wishlist: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Add a product to the customer's wishlist.
     * POST /api/v1/customer/wishlist
     * Body: { product_id: int }
     */
    public function store(Request $request): void
    {
        $customerId = $this->resolveCustomerId($request);
        $productId = (int)$request->get('product_id', 0);

        if ($productId <= 0) {
            Response::error('Validation failed: A valid product_id is required.', 422, [
                'product_id' => 'Valid product ID is required.'
            ]);
        }

        try {
            $pdo = Database::getConnection();

            // Validate that product exists
            $prodStmt = $pdo->prepare('SELECT id, title, status FROM products WHERE id = :id LIMIT 1');
            $prodStmt->execute(['id' => $productId]);
            $product = $prodStmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                Response::error('Product not found.', 404);
            }

            // Upsert / Insert ignore duplicate wishlist item
            $insStmt = $pdo->prepare(
                'INSERT INTO wishlist_items (customer_id, product_id, created_at, updated_at)
                 VALUES (:customer_id, :product_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
            );
            $insStmt->execute([
                'customer_id' => $customerId,
                'product_id'  => $productId,
            ]);

            $data = $this->buildWishlistResponse($pdo, $customerId);
            Response::json($data, 201, 'Product added to wishlist successfully');
        } catch (Throwable $e) {
            Response::error('Failed to add product to wishlist: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove an item from the customer's wishlist.
     * DELETE /api/v1/customer/wishlist/{id}
     * Param: {id} can be the wishlist_item id OR product id.
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
                'DELETE FROM wishlist_items
                 WHERE (id = :item_id OR product_id = :item_id_prod)
                   AND customer_id = :customer_id'
            );
            $delStmt->execute([
                'item_id'      => $itemId,
                'item_id_prod' => $itemId,
                'customer_id'  => $customerId,
            ]);

            if ($delStmt->rowCount() === 0) {
                Response::error('Wishlist item not found.', 404);
            }

            $data = $this->buildWishlistResponse($pdo, $customerId);
            Response::json($data, 200, 'Product removed from wishlist successfully');
        } catch (Throwable $e) {
            Response::error('Failed to remove product from wishlist: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Clear all items from the customer's wishlist.
     * DELETE /api/v1/customer/wishlist
     */
    public function clear(Request $request): void
    {
        $customerId = $this->resolveCustomerId($request);

        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare('DELETE FROM wishlist_items WHERE customer_id = :customer_id');
            $stmt->execute(['customer_id' => $customerId]);

            $data = $this->buildWishlistResponse($pdo, $customerId);
            Response::json($data, 200, 'Wishlist cleared successfully');
        } catch (Throwable $e) {
            Response::error('Failed to clear wishlist: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Build standard response format for wishlist queries.
     */
    private function buildWishlistResponse(PDO $pdo, int $customerId): array
    {
        $sql = "SELECT
                    wi.id AS wishlist_item_id,
                    wi.created_at AS saved_at,
                    p.id AS product_id,
                    p.seller_id,
                    p.title AS product_title,
                    p.slug AS product_slug,
                    p.description,
                    p.price AS current_price,
                    p.mrp,
                    p.stock_quantity,
                    p.status AS product_status,
                    c.name AS category_name,
                    sp.store_name AS seller_store_name,
                    u.is_active AS seller_active,
                    (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image_url
                FROM wishlist_items wi
                JOIN products p ON wi.product_id = p.id
                JOIN users u ON p.seller_id = u.id
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN seller_profiles sp ON p.seller_id = sp.user_id
                WHERE wi.customer_id = :customer_id
                ORDER BY wi.created_at DESC, wi.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['customer_id' => $customerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        $totalItems = count($rows);
        $inStockCount = 0;
        $priceDropCount = 0;

        foreach ($rows as $row) {
            $price = (float)$row['current_price'];
            $mrp = $row['mrp'] !== null ? (float)$row['mrp'] : null;
            $stock = (int)$row['stock_quantity'];
            $isInStock = ($row['product_status'] === 'published') && ((int)$row['seller_active'] === 1) && ($stock > 0);
            $hasPriceDrop = ($mrp !== null && $mrp > $price);

            if ($isInStock) {
                $inStockCount++;
            }
            if ($hasPriceDrop) {
                $priceDropCount++;
            }

            $items[] = [
                'wishlist_item_id'  => (int)$row['wishlist_item_id'],
                'product_id'        => (int)$row['product_id'],
                'seller_id'         => (int)$row['seller_id'],
                'seller_store_name' => $row['seller_store_name'] ?? 'Authorized Seller',
                'category_name'     => $row['category_name'] ?? 'Electronics',
                'title'             => $row['product_title'],
                'slug'              => $row['product_slug'],
                'description'       => $row['description'] ?? '',
                'primary_image_url' => $row['primary_image_url'],
                'price'             => $price,
                'mrp'               => $mrp,
                'stock_quantity'    => $stock,
                'is_in_stock'       => $isInStock,
                'is_low_stock'      => ($isInStock && $stock <= 3),
                'has_price_drop'    => $hasPriceDrop,
                'saved_at'          => $row['saved_at'],
            ];
        }

        return [
            'items'   => $items,
            'summary' => [
                'total_items'      => $totalItems,
                'in_stock_count'   => $inStockCount,
                'price_drop_count' => $priceDropCount,
            ]
        ];
    }
}
