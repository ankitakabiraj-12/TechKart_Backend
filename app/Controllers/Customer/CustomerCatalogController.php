<?php

declare(strict_types=1);

namespace App\Controllers\Customer;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Controller handling public Customer Catalog (Products & Categories)
 */
class CustomerCatalogController
{
    /**
     * List active categories (hierarchical or flat)
     * GET /api/v1/customer/categories
     * Query Params:
     * - parent_id: int | 'root' | 'null' (optional)
     * - nested: 1 or true (optional - returns root categories with nested subcategories)
     */
    public function categories(Request $request): void
    {
        try {
            $pdo = Database::getConnection();
            $parentId = $request->getQuery('parent_id');
            $nested = $request->getQuery('nested');

            if ($nested === '1' || $nested === 'true') {
                // Fetch roots and children in nested tree
                $rootStmt = $pdo->prepare(
                    'SELECT id, parent_id, name, slug, image_url, display_order, created_at
                     FROM categories
                     WHERE parent_id IS NULL AND is_active = 1
                     ORDER BY display_order ASC, id ASC'
                );
                $rootStmt->execute();
                $roots = $rootStmt->fetchAll(PDO::FETCH_ASSOC);

                $childStmt = $pdo->prepare(
                    'SELECT id, parent_id, name, slug, image_url, display_order, created_at
                     FROM categories
                     WHERE parent_id IS NOT NULL AND is_active = 1
                     ORDER BY display_order ASC, id ASC'
                );
                $childStmt->execute();
                $allChildren = $childStmt->fetchAll(PDO::FETCH_ASSOC);

                $childrenByParent = [];
                foreach ($allChildren as $child) {
                    $pid = (int)$child['parent_id'];
                    $childrenByParent[$pid][] = $child;
                }

                foreach ($roots as &$r) {
                    $rId = (int)$r['id'];
                    $r['subcategories'] = $childrenByParent[$rId] ?? [];
                }
                unset($r);

                Response::json($roots, 200, 'Categories retrieved successfully');
                return;
            }

            $conditions = ['is_active = 1'];
            $params = [];

            if ($parentId !== null && $parentId !== '') {
                if ($parentId === 'root' || $parentId === 'null' || $parentId === '0') {
                    $conditions[] = 'parent_id IS NULL';
                } else {
                    $conditions[] = 'parent_id = :parent_id';
                    $params['parent_id'] = (int)$parentId;
                }
            }

            $whereSql = implode(' AND ', $conditions);
            $stmt = $pdo->prepare(
                "SELECT id, parent_id, name, slug, image_url, display_order, created_at
                 FROM categories
                 WHERE {$whereSql}
                 ORDER BY display_order ASC, id ASC"
            );
            $stmt->execute($params);
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::json($categories, 200, 'Categories retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to retrieve categories: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get subcategories for a specific parent category ID or slug
     * GET /api/v1/customer/categories/{id}/subcategories
     */
    public function subcategories(Request $request, array $params = []): void
    {
        $idOrSlug = $params['id'] ?? $request->getQuery('parent_id');
        if (empty($idOrSlug)) {
            Response::error('Parent category ID or slug required', 400);
            return;
        }

        try {
            $pdo = Database::getConnection();

            if (is_numeric($idOrSlug)) {
                $pStmt = $pdo->prepare('SELECT id, parent_id, name, slug, image_url, display_order FROM categories WHERE id = :id AND is_active = 1 LIMIT 1');
                $pStmt->execute(['id' => (int)$idOrSlug]);
            } else {
                $pStmt = $pdo->prepare('SELECT id, parent_id, name, slug, image_url, display_order FROM categories WHERE slug = :slug AND is_active = 1 LIMIT 1');
                $pStmt->execute(['slug' => $idOrSlug]);
            }

            $parent = $pStmt->fetch(PDO::FETCH_ASSOC);
            if (!$parent) {
                Response::error('Category not found', 404);
                return;
            }

            $stmt = $pdo->prepare(
                'SELECT id, parent_id, name, slug, image_url, display_order, created_at
                 FROM categories
                 WHERE parent_id = :pid AND is_active = 1
                 ORDER BY display_order ASC, id ASC'
            );
            $stmt->execute(['pid' => (int)$parent['id']]);
            $subcategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::json([
                'category' => $parent,
                'subcategories' => $subcategories,
                'total' => count($subcategories),
            ], 200, 'Subcategories retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to retrieve subcategories: ' . $e->getMessage(), 500);
        }
    }

    /**
     * List published products with filtering, search, sorting, and pagination
     * GET /api/v1/customer/products
     * Query Params:
     * - search: string
     * - category_id: int
     * - min_price: float
     * - max_price: float
     * - sort_by: price_asc | price_desc | latest | oldest | title_asc | title_desc
     * - in_stock: 1 or 0
     * - page: int (default 1)
     * - limit: int (default 20, max 100)
     */
    public function index(Request $request): void
    {
        try {
            $pdo = Database::getConnection();

            $search         = trim((string)$request->getQuery('search', ''));
            $categoryId     = $request->getQuery('category_id');
            $subcategoryId  = $request->getQuery('subcategory_id');
            $categorySlug   = trim((string)$request->getQuery('category', ''));
            $subcategorySlug = trim((string)$request->getQuery('subcategory', ''));
            $minPrice       = $request->getQuery('min_price');
            $maxPrice       = $request->getQuery('max_price');
            $inStock        = $request->getQuery('in_stock');
            $sortBy         = (string)$request->getQuery('sort_by', 'latest');

            $page  = max(1, (int)$request->getQuery('page', 1));
            $limit = min(100, max(1, (int)$request->getQuery('limit', 20)));
            $offset = ($page - 1) * $limit;

            $conditions = ["p.status = 'published'", "u.is_active = 1"];
            $params = [];

            if ($search !== '') {
                $conditions[] = '(p.title LIKE :search OR p.description LIKE :search_desc)';
                $params['search'] = '%' . $search . '%';
                $params['search_desc'] = '%' . $search . '%';
            }

            if ($categoryId !== null && $categoryId !== '') {
                $conditions[] = 'p.category_id = :category_id';
                $params['category_id'] = (int)$categoryId;
            }

            if ($subcategoryId !== null && $subcategoryId !== '') {
                $conditions[] = 'p.subcategory_id = :subcategory_id';
                $params['subcategory_id'] = (int)$subcategoryId;
            }

            if ($categorySlug !== '') {
                $conditions[] = 'c.slug = :category_slug';
                $params['category_slug'] = $categorySlug;
            }

            if ($subcategorySlug !== '') {
                $conditions[] = 'sub.slug = :subcategory_slug';
                $params['subcategory_slug'] = $subcategorySlug;
            }

            if ($minPrice !== null && is_numeric($minPrice)) {
                $conditions[] = 'p.price >= :min_price';
                $params['min_price'] = (float)$minPrice;
            }

            if ($maxPrice !== null && is_numeric($maxPrice)) {
                $conditions[] = 'p.price <= :max_price';
                $params['max_price'] = (float)$maxPrice;
            }

            if ($inStock === '1' || $inStock === 'true') {
                $conditions[] = 'p.stock_quantity > 0';
            }

            $whereSql = implode(' AND ', $conditions);

            // Sorting order map
            $orderBy = match ($sortBy) {
                'price_asc'  => 'p.price ASC, p.id DESC',
                'price_desc' => 'p.price DESC, p.id DESC',
                'title_asc'  => 'p.title ASC',
                'title_desc' => 'p.title DESC',
                'oldest'     => 'p.created_at ASC, p.id ASC',
                default      => 'p.created_at DESC, p.id DESC',
            };

            // 1. Count total matching products
            $countSql = "SELECT COUNT(p.id) AS total
                         FROM products p
                         JOIN users u ON p.seller_id = u.id
                         LEFT JOIN categories c ON p.category_id = c.id
                         LEFT JOIN categories sub ON p.subcategory_id = sub.id
                         WHERE {$whereSql}";
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            // 2. Fetch page results
            $sql = "SELECT
                        p.id,
                        p.seller_id,
                        p.category_id,
                        p.subcategory_id,
                        p.title,
                        p.slug,
                        p.description,
                        p.price,
                        p.mrp,
                        p.stock_quantity,
                        p.status,
                        p.created_at,
                        p.updated_at,
                        c.name AS category_name,
                        c.slug AS category_slug,
                        sub.name AS subcategory_name,
                        sub.slug AS subcategory_slug,
                        sp.store_name AS seller_store_name,
                        (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image_url
                    FROM products p
                    JOIN users u ON p.seller_id = u.id
                    LEFT JOIN categories c ON p.category_id = c.id
                    LEFT JOIN categories sub ON p.subcategory_id = sub.id
                    LEFT JOIN seller_profiles sp ON p.seller_id = sp.user_id
                    WHERE {$whereSql}
                    ORDER BY {$orderBy}
                    LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue(':' . $key, $val);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch secondary images for returned products
            if (!empty($products)) {
                $productIds = array_column($products, 'id');
                $inQuery = implode(',', array_map('intval', $productIds));
                $imgStmt = $pdo->query("SELECT product_id, image_url, is_primary, sort_order FROM product_images WHERE product_id IN ({$inQuery}) ORDER BY sort_order ASC, id ASC");
                $images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);

                $imagesByProduct = [];
                foreach ($images as $img) {
                    $imagesByProduct[$img['product_id']][] = [
                        'image_url'  => $img['image_url'],
                        'is_primary' => (int)$img['is_primary'] === 1,
                        'sort_order' => (int)$img['sort_order'],
                    ];
                }

                foreach ($products as &$prod) {
                    $pid = $prod['id'];
                    $prod['images'] = $imagesByProduct[$pid] ?? [];
                    if (empty($prod['primary_image_url']) && !empty($prod['images'])) {
                        $prod['primary_image_url'] = $prod['images'][0]['image_url'];
                    }
                    $prod['price'] = (float)$prod['price'];
                    $prod['mrp'] = $prod['mrp'] !== null ? (float)$prod['mrp'] : null;
                    $prod['stock_quantity'] = (int)$prod['stock_quantity'];
                }
                unset($prod);
            }

            Response::json($products, 200, 'Products retrieved successfully', [
                'pagination' => [
                    'total'        => $total,
                    'page'         => $page,
                    'limit'        => $limit,
                    'total_pages'  => ceil($total / $limit) ?: 1,
                    'has_next'     => ($offset + $limit) < $total,
                    'has_previous' => $page > 1,
                ]
            ]);

        } catch (Throwable $e) {
            Response::error('Failed to retrieve products: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get single published product details with full image gallery, category, and seller info
     * GET /api/v1/customer/products/{id}
     */
    public function show(Request $request, array $params): void
    {
        $ident = trim((string)($params['id'] ?? ''));
        if ($ident === '') {
            Response::error('Invalid product identifier.', 400);
        }

        $isNumeric = ctype_digit($ident);

        try {
            $pdo = Database::getConnection();

            $sql = "SELECT
                        p.id,
                        p.seller_id,
                        p.category_id,
                        p.subcategory_id,
                        p.title,
                        p.slug,
                        p.description,
                        p.price,
                        p.mrp,
                        p.stock_quantity,
                        p.status,
                        p.created_at,
                        p.updated_at,
                        c.name AS category_name,
                        c.slug AS category_slug,
                        sub.name AS subcategory_name,
                        sub.slug AS subcategory_slug,
                        sp.store_name AS seller_store_name,
                        sp.city AS seller_city,
                        sp.state AS seller_state,
                        sp.is_verified AS seller_is_verified
                    FROM products p
                    JOIN users u ON p.seller_id = u.id
                    LEFT JOIN categories c ON p.category_id = c.id
                    LEFT JOIN categories sub ON p.subcategory_id = sub.id
                    LEFT JOIN seller_profiles sp ON p.seller_id = sp.user_id
                    WHERE " . ($isNumeric ? '(p.id = :id OR p.slug = :slug)' : 'p.slug = :slug') . "
                      AND p.status = 'published' AND u.is_active = 1
                    LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $binds = ['slug' => $ident];
            if ($isNumeric) {
                $binds['id'] = (int)$ident;
            }
            $stmt->execute($binds);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                Response::error('Product not found or currently unavailable.', 404);
            }

            $id = (int)$product['id'];

            // Fetch product images
            $imgStmt = $pdo->prepare(
                'SELECT id, image_url, is_primary, sort_order
                 FROM product_images
                 WHERE product_id = :product_id
                 ORDER BY is_primary DESC, sort_order ASC, id ASC'
            );
            $imgStmt->execute(['product_id' => $id]);
            $images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);

            $product['price'] = (float)$product['price'];
            $product['mrp'] = $product['mrp'] !== null ? (float)$product['mrp'] : null;
            $product['stock_quantity'] = (int)$product['stock_quantity'];
            $product['seller_is_verified'] = (int)($product['seller_is_verified'] ?? 0);
            $product['images'] = array_map(function ($img) {
                return [
                    'id'         => (int)$img['id'],
                    'image_url'  => $img['image_url'],
                    'is_primary' => (int)$img['is_primary'] === 1,
                    'sort_order' => (int)$img['sort_order'],
                ];
            }, $images);

            Response::json($product, 200, 'Product details retrieved successfully');

        } catch (Throwable $e) {
            Response::error('Failed to retrieve product details: ' . $e->getMessage(), 500);
        }
    }
}
