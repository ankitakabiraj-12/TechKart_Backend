<?php

declare(strict_types=1);

namespace App\Controllers\Buddy;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Controller handling Product Catalog Management for Sellers (TechKart Buddy)
 */
class BuddyProductController
{
    /**
     * Helper to resolve seller ID.
     * Sellers can ONLY access their own products (returns seller_id int).
     * Admins can manage any product, view all products (null), or filter by specific seller_id.
     */
    private function resolveSellerId(Request $request): ?int
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('Unauthorized', 401);
        }

        if ($user['role'] === 'seller') {
            return (int)$user['id'];
        }

        if ($user['role'] === 'admin') {
            $overrideId = $request->get('seller_id') ?? $request->getQuery('seller_id');
            return $overrideId !== null && $overrideId !== '' ? (int)$overrideId : null;
        }

        Response::error('Forbidden', 403);
        exit;
    }

    /**
     * List only the authenticated seller's products (including drafts and archived)
     * GET /api/v1/buddy/products
     */
    public function index(Request $request): void
    {
        $sellerId = $this->resolveSellerId($request);

        $status = $request->getQuery('status'); // draft, published, archived, or null
        $search = trim((string)$request->getQuery('search', ''));
        $page   = max(1, (int)$request->getQuery('page', 1));
        $limit  = min(100, max(1, (int)$request->getQuery('limit', 20)));
        $offset = ($page - 1) * $limit;

        try {
            $pdo = Database::getConnection();

            $conditions = [];
            $params = [];

            if ($sellerId !== null) {
                $conditions[] = 'p.seller_id = :seller_id';
                $params['seller_id'] = $sellerId;
            }

            if ($status && in_array($status, ['draft', 'pending_review', 'approved', 'rejected', 'published', 'archived'], true)) {
                $conditions[] = 'p.status = :status';
                $params['status'] = $status;
            }

            if ($search !== '') {
                $conditions[] = '(p.title LIKE :search OR p.description LIKE :search_desc)';
                $params['search'] = '%' . $search . '%';
                $params['search_desc'] = '%' . $search . '%';
            }

            $whereSql = !empty($conditions) ? implode(' AND ', $conditions) : '1=1';

            // Total count
            $countStmt = $pdo->prepare("SELECT COUNT(id) FROM products p WHERE {$whereSql}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            // Fetch products with approval and review fields
            $sql = "SELECT
                        p.id,
                        p.seller_id,
                        p.category_id,
                        p.title,
                        p.slug,
                        p.description,
                        p.price,
                        p.mrp,
                        p.stock_quantity,
                        p.low_stock_threshold,
                        p.status,
                        p.review_notes,
                        p.reviewed_by,
                        p.reviewed_at,
                        p.created_at,
                        p.updated_at,
                        c.name AS category_name,
                        sp.store_name AS seller_store_name,
                        u_rev.full_name AS reviewer_name,
                        (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image_url
                    FROM products p
                    LEFT JOIN categories c ON p.category_id = c.id
                    LEFT JOIN seller_profiles sp ON p.seller_id = sp.user_id
                    LEFT JOIN users u_rev ON p.reviewed_by = u_rev.id
                    WHERE {$whereSql}
                    ORDER BY p.id DESC
                    LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch images for each
            if (!empty($products)) {
                $productIds = array_column($products, 'id');
                $inQuery = implode(',', array_map('intval', $productIds));
                $imgStmt = $pdo->query("SELECT id, product_id, image_url, is_primary, sort_order FROM product_images WHERE product_id IN ({$inQuery}) ORDER BY sort_order ASC, id ASC");
                $images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);

                $imagesByProduct = [];
                foreach ($images as $img) {
                    $imagesByProduct[$img['product_id']][] = [
                        'id'         => (int)$img['id'],
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
                    $prod['low_stock_threshold'] = (int)($prod['low_stock_threshold'] ?? 5);
                }
                unset($prod);
            }

            Response::json($products, 200, 'Seller products retrieved successfully', [
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
     * Get single seller product (only if owned by authenticated seller)
     * GET /api/v1/buddy/products/{id}
     */
    public function show(Request $request, array $params): void
    {
        $sellerId = $this->resolveSellerId($request);
        $productId = (int)($params['id'] ?? 0);

        if ($productId <= 0) {
            Response::error('Invalid product ID.', 400);
        }

        try {
            $pdo = Database::getConnection();

            $existStmt = $pdo->prepare('SELECT id, seller_id FROM products WHERE id = :id LIMIT 1');
            $existStmt->execute(['id' => $productId]);
            $existingProduct = $existStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existingProduct) {
                Response::error('Product not found.', 404);
            }

            if ($sellerId !== null && (int)$existingProduct['seller_id'] !== $sellerId) {
                Response::error('Forbidden. This product belongs to another seller.', 403);
            }

            $sql = 'SELECT p.*,
                           c.name AS category_name,
                           c.slug AS category_slug,
                           sub.name AS subcategory_name,
                           sub.slug AS subcategory_slug,
                           sp.store_name AS seller_store_name,
                           u_rev.full_name AS reviewer_name,
                           u_rev.email AS reviewer_email
                    FROM products p
                    LEFT JOIN categories c ON p.category_id = c.id
                    LEFT JOIN categories sub ON p.subcategory_id = sub.id
                    LEFT JOIN seller_profiles sp ON p.seller_id = sp.user_id
                    LEFT JOIN users u_rev ON p.reviewed_by = u_rev.id
                    WHERE p.id = :id LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            $imgStmt = $pdo->prepare(
                'SELECT id, image_url, is_primary, sort_order
                 FROM product_images
                 WHERE product_id = :product_id
                 ORDER BY is_primary DESC, sort_order ASC, id ASC'
            );
            $imgStmt->execute(['product_id' => $productId]);
            $product['images'] = $imgStmt->fetchAll(PDO::FETCH_ASSOC);

            $product['price'] = (float)$product['price'];
            $product['mrp'] = $product['mrp'] !== null ? (float)$product['mrp'] : null;
            $product['stock_quantity'] = (int)$product['stock_quantity'];
            $product['low_stock_threshold'] = (int)($product['low_stock_threshold'] ?? 5);
            if (isset($product['specifications']) && is_string($product['specifications'])) {
                $product['specifications'] = json_decode($product['specifications'], true);
            }

            Response::json($product, 200, 'Product retrieved successfully');

        } catch (Throwable $e) {
            Response::error('Failed to retrieve product: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create a new product for the authenticated seller
     * POST /api/v1/buddy/products
     * Body: {
     *   category_id?: int,
     *   title: string,
     *   description?: string,
     *   price: float,
     *   mrp?: float,
     *   stock_quantity?: int,
     *   status?: 'draft'|'published'|'archived',
     *   images?: array<string | { image_url: string, is_primary?: bool }>
     * }
     */
    public function store(Request $request): void
    {
        $sellerId = $this->resolveSellerId($request);
        $user = $request->getUser();

        $title          = trim((string)$request->get('title', ''));
        $categoryId     = $request->get('category_id') ? (int)$request->get('category_id') : null;
        $subcategoryId  = $request->get('subcategory_id') ? (int)$request->get('subcategory_id') : null;
        $description    = $request->get('description') !== null ? trim((string)$request->get('description')) : null;
        $specifications = $request->get('specifications');
        $price          = $request->get('price');
        $mrp            = $request->get('mrp') !== null && $request->get('mrp') !== '' ? (float)$request->get('mrp') : null;
        $stockQuantity  = (int)$request->get('stock_quantity', 0);
        $threshold      = max(1, (int)($request->get('low_stock_threshold') ?? $request->get('threshold', 5)));
        $status         = (string)$request->get('status', 'pending_review');
        $images         = $request->get('images', []);

        // Validation
        $errors = [];
        if ($user && $user['role'] === 'admin') {
            if ($sellerId === null || $sellerId <= 0) {
                $errors['seller_id'] = 'A valid seller_id must be specified when creating a product as administrator.';
            }
        }
        if (mb_strlen($title) < 3) {
            $errors['title'] = 'Product title must be at least 3 characters long.';
        }
        if ($price === null || !is_numeric($price) || (float)$price < 0) {
            $errors['price'] = 'A valid positive price is required.';
        }
        if ($mrp !== null && $mrp < (float)$price) {
            $errors['mrp'] = 'MRP cannot be less than selling price.';
        }
        $allowedStatuses = ['draft', 'pending_review', 'approved', 'rejected', 'published', 'archived'];
        if (!in_array($status, $allowedStatuses, true)) {
            $errors['status'] = "Invalid status. Allowed values: 'draft', 'pending_review', 'approved', 'rejected', 'published', 'archived'.";
        }
        // Non-admin sellers cannot unilaterally mark products as 'approved' or 'published' directly upon creation
        if ($user && $user['role'] === 'seller') {
            if ($status === 'published' || $status === 'approved') {
                $status = 'pending_review';
            }
        }

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $pdo = Database::getConnection();

            // Validate seller existence if admin
            if ($user && $user['role'] === 'admin' && $sellerId !== null) {
                $chkSeller = $pdo->prepare("SELECT id FROM users WHERE id = :id AND role = 'seller' LIMIT 1");
                $chkSeller->execute(['id' => $sellerId]);
                if (!$chkSeller->fetch()) {
                    Response::error('Specified seller does not exist.', 422, ['seller_id' => 'Seller not found']);
                }
            }

            // Validate category existence if provided
            if ($categoryId !== null) {
                $chkCat = $pdo->prepare('SELECT id FROM categories WHERE id = :id AND parent_id IS NULL LIMIT 1');
                $chkCat->execute(['id' => $categoryId]);
                if (!$chkCat->fetch()) {
                    Response::error('Specified category does not exist.', 422, ['category_id' => 'Main category not found']);
                }
            }

            // Validate subcategory existence and parent match if provided
            if ($subcategoryId !== null) {
                if ($categoryId === null) {
                    Response::error('Category ID must be provided when subcategory is specified.', 422, ['category_id' => 'Category ID required']);
                }
                $chkSub = $pdo->prepare('SELECT id, parent_id FROM categories WHERE id = :id AND is_active = 1 LIMIT 1');
                $chkSub->execute(['id' => $subcategoryId]);
                $subRow = $chkSub->fetch(PDO::FETCH_ASSOC);
                if (!$subRow) {
                    Response::error('Specified subcategory does not exist.', 422, ['subcategory_id' => 'Subcategory not found']);
                }
                if ((int)$subRow['parent_id'] !== $categoryId) {
                    Response::error('Subcategory does not belong to the selected main category.', 422, ['subcategory_id' => 'Subcategory mismatch']);
                }
            }

            $pdo->beginTransaction();

            // Generate unique slug
            $baseSlug = $this->slugify($title);
            $slug = $baseSlug;
            $slugSuffix = 1;
            while (true) {
                $chkSlug = $pdo->prepare('SELECT id FROM products WHERE slug = :slug LIMIT 1');
                $chkSlug->execute(['slug' => $slug]);
                if (!$chkSlug->fetch()) {
                    break;
                }
                $slug = $baseSlug . '-' . (++$slugSuffix);
            }

            $specificationsJson = null;
            if ($specifications !== null) {
                if (is_string($specifications)) {
                    $specificationsJson = $specifications;
                } else {
                    $specificationsJson = json_encode($specifications, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }

            $stmt = $pdo->prepare(
                'INSERT INTO products (seller_id, category_id, subcategory_id, title, slug, description, specifications, price, mrp, stock_quantity, low_stock_threshold, status)
                 VALUES (:seller_id, :category_id, :subcategory_id, :title, :slug, :description, :specifications, :price, :mrp, :stock_quantity, :low_stock_threshold, :status)'
            );
            $stmt->execute([
                'seller_id'           => $sellerId,
                'category_id'         => $categoryId,
                'subcategory_id'      => $subcategoryId,
                'title'               => $title,
                'slug'                => $slug,
                'description'         => $description,
                'specifications'      => $specificationsJson,
                'price'               => (float)$price,
                'mrp'                 => $mrp,
                'stock_quantity'      => $stockQuantity,
                'low_stock_threshold' => $threshold,
                'status'              => $status,
            ]);

            $productId = (int)$pdo->lastInsertId();

            // Insert images if provided
            if (is_array($images) && !empty($images)) {
                $imgStmt = $pdo->prepare(
                    'INSERT INTO product_images (product_id, image_url, is_primary, sort_order)
                     VALUES (:product_id, :image_url, :is_primary, :sort_order)'
                );

                $sortOrder = 0;
                foreach ($images as $index => $imgItem) {
                    $imageUrl = is_array($imgItem) ? ($imgItem['image_url'] ?? '') : (string)$imgItem;
                    $isPrimary = is_array($imgItem) ? (int)(!empty($imgItem['is_primary'])) : ($index === 0 ? 1 : 0);

                    if (!empty($imageUrl)) {
                        $imgStmt->execute([
                            'product_id' => $productId,
                            'image_url'  => trim($imageUrl),
                            'is_primary' => $isPrimary,
                            'sort_order' => $sortOrder++,
                        ]);
                    }
                }
            }

            $pdo->commit();

            Response::json([
                'id'                  => $productId,
                'seller_id'           => $sellerId,
                'category_id'         => $categoryId,
                'subcategory_id'      => $subcategoryId,
                'title'               => $title,
                'slug'                => $slug,
                'description'         => $description,
                'specifications'      => $specifications !== null && is_string($specifications) ? json_decode($specifications, true) : $specifications,
                'price'               => (float)$price,
                'mrp'                 => $mrp,
                'stock_quantity'      => $stockQuantity,
                'low_stock_threshold' => $threshold,
                'status'              => $status,
            ], 201, 'Product created successfully');

        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Failed to create product: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update an existing product owned by the seller
     * PUT/PATCH /api/v1/buddy/products/{id}
     */
    public function update(Request $request, array $params): void
    {
        $sellerId  = $this->resolveSellerId($request);
        $user      = $request->getUser();
        $productId = (int)($params['id'] ?? 0);

        if ($productId <= 0) {
            Response::error('Invalid product ID.', 400);
        }

        try {
            $pdo = Database::getConnection();

            // Check product ownership (sellers can only update own products; admins can update any)
            $checkStmt = $pdo->prepare('SELECT id, seller_id, category_id, subcategory_id, title, slug, price, mrp FROM products WHERE id = :id LIMIT 1');
            $checkStmt->execute(['id' => $productId]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                Response::error('Product not found.', 404);
            }

            if ($sellerId !== null && (int)$existing['seller_id'] !== $sellerId) {
                Response::error('Forbidden. You do not have permission to modify products belonging to another seller.', 403);
            }

            $productSellerId = (int)$existing['seller_id'];
            $existingPrice   = (float)$existing['price'];
            $existingMrp     = $existing['mrp'] !== null ? (float)$existing['mrp'] : null;

            $title          = $request->get('title');
            $categoryId     = $request->get('category_id');
            $subcategoryId  = $request->get('subcategory_id');
            $description    = $request->get('description');
            $specifications = $request->get('specifications');
            $price          = $request->get('price');
            $mrp            = $request->get('mrp');
            $stockQuantity  = $request->get('stock_quantity');
            $status         = $request->get('status');
            $images         = $request->get('images');

            $errors = [];

            if ($title !== null) {
                $title = trim((string)$title);
                if (mb_strlen($title) < 3) {
                    $errors['title'] = 'Title must be at least 3 characters long.';
                }
            }

            if ($price !== null) {
                if (!is_numeric($price) || (float)$price < 0) {
                    $errors['price'] = 'Price must be a positive number.';
                }
            }

            if ($mrp !== null && $mrp !== '') {
                if (!is_numeric($mrp) || (float)$mrp < 0) {
                    $errors['mrp'] = 'MRP must be a positive number.';
                }
            }

            // Cross-validate final price against final MRP (existing or updated)
            $finalPrice = ($price !== null && is_numeric($price)) ? (float)$price : $existingPrice;
            $finalMrp = ($mrp !== null) ? ($mrp !== '' ? (float)$mrp : null) : $existingMrp;

            if ($finalMrp !== null && $finalMrp < $finalPrice) {
                $errors['mrp'] = 'MRP cannot be less than selling price.';
            }

            if ($status !== null && !in_array($status, ['draft', 'pending_review', 'approved', 'rejected', 'published', 'archived'], true)) {
                $errors['status'] = "Invalid status value. Allowed: 'draft', 'pending_review', 'approved', 'rejected', 'published', 'archived'.";
            }

            // If seller is updating a product that was rejected or is in draft and marks it as pending_review or published:
            if ($user && $user['role'] === 'seller' && $status !== null) {
                if ($status === 'published' || $status === 'approved') {
                    // Sellers cannot self-approve; only if already approved can a seller publish, or it re-enters review
                    if ($existingProduct['status'] === 'approved') {
                        // Seller can toggle between approved and published
                        $status = 'published';
                    } else {
                        // Submit for review
                        $status = 'pending_review';
                    }
                }
            }

            if (!empty($errors)) {
                Response::error('Validation failed', 422, $errors);
            }

            $updates = [];
            $binds = ['id' => $productId];

            if ($sellerId !== null) {
                $binds['seller_id'] = $sellerId;
            }

            if ($title !== null) {
                $updates[] = 'title = :title';
                $binds['title'] = $title;
            }

            // Determine effective category_id for validation
            $effectiveCategoryId = $categoryId !== null ? ($categoryId !== '' ? (int)$categoryId : null) : (int)$existing['category_id'];

            if ($categoryId !== null) {
                $catId = $categoryId !== '' ? (int)$categoryId : null;
                if ($catId !== null) {
                    $chkCat = $pdo->prepare('SELECT id, parent_id, is_active FROM categories WHERE id = :id LIMIT 1');
                    $chkCat->execute(['id' => $catId]);
                    $catRow = $chkCat->fetch(PDO::FETCH_ASSOC);
                    if (!$catRow) {
                        Response::error('Category does not exist.', 422, ['category_id' => 'Category not found']);
                    }
                    if ($catRow['parent_id'] !== null) {
                        Response::error('Invalid category. Must be a root/main category.', 422, ['category_id' => 'Must be a root category.']);
                    }
                }
                $updates[] = 'category_id = :category_id';
                $binds['category_id'] = $catId;
            }

            if ($subcategoryId !== null) {
                $subId = ($subcategoryId !== '' && $subcategoryId !== null) ? (int)$subcategoryId : null;
                if ($subId !== null) {
                    $chkSub = $pdo->prepare('SELECT id, parent_id, is_active FROM categories WHERE id = :id LIMIT 1');
                    $chkSub->execute(['id' => $subId]);
                    $subRow = $chkSub->fetch(PDO::FETCH_ASSOC);
                    if (!$subRow) {
                        Response::error('Subcategory does not exist.', 422, ['subcategory_id' => 'Subcategory not found.']);
                    }
                    if ((int)$subRow['parent_id'] !== $effectiveCategoryId) {
                        Response::error('Subcategory does not belong to the selected category.', 422, ['subcategory_id' => 'Subcategory does not belong to category ' . $effectiveCategoryId]);
                    }
                }
                $updates[] = 'subcategory_id = :subcategory_id';
                $binds['subcategory_id'] = $subId;
            }

            if ($description !== null) {
                $updates[] = 'description = :description';
                $binds['description'] = trim((string)$description);
            }

            if ($specifications !== null) {
                $updates[] = 'specifications = :specifications';
                $binds['specifications'] = is_string($specifications)
                    ? $specifications
                    : json_encode($specifications, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if ($price !== null) {
                $updates[] = 'price = :price';
                $binds['price'] = (float)$price;
            }

            if ($mrp !== null) {
                $mrpVal = $mrp !== '' ? (float)$mrp : null;
                $updates[] = 'mrp = :mrp';
                $binds['mrp'] = $mrpVal;
            }

            if ($stockQuantity !== null) {
                $updates[] = 'stock_quantity = :stock_quantity';
                $binds['stock_quantity'] = max(0, (int)$stockQuantity);
            }

            $lowStockThreshold = $request->get('low_stock_threshold') ?? $request->get('threshold');
            if ($lowStockThreshold !== null) {
                $updates[] = 'low_stock_threshold = :low_stock_threshold';
                $binds['low_stock_threshold'] = max(1, (int)$lowStockThreshold);
            }

            if ($status !== null) {
                $updates[] = 'status = :status';
                $binds['status'] = $status;
            }

            $pdo->beginTransaction();

            if (!empty($updates)) {
                $whereClause = 'WHERE id = :id';
                if ($sellerId !== null) {
                    $whereClause .= ' AND seller_id = :seller_id';
                }
                $sql = 'UPDATE products SET ' . implode(', ', $updates) . ' ' . $whereClause;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($binds);
            }

            // Sync images if images array is explicitly provided
            if ($images !== null && is_array($images)) {
                $delImages = $pdo->prepare('DELETE FROM product_images WHERE product_id = :product_id');
                $delImages->execute(['product_id' => $productId]);

                $imgStmt = $pdo->prepare(
                    'INSERT INTO product_images (product_id, image_url, is_primary, sort_order)
                     VALUES (:product_id, :image_url, :is_primary, :sort_order)'
                );

                $sortOrder = 0;
                foreach ($images as $index => $imgItem) {
                    $imageUrl = is_array($imgItem) ? ($imgItem['image_url'] ?? '') : (string)$imgItem;
                    $isPrimary = is_array($imgItem) ? (int)(!empty($imgItem['is_primary'])) : ($index === 0 ? 1 : 0);

                    if (!empty($imageUrl)) {
                        $imgStmt->execute([
                            'product_id' => $productId,
                            'image_url'  => trim($imageUrl),
                            'is_primary' => $isPrimary,
                            'sort_order' => $sortOrder++,
                        ]);
                    }
                }
            }

            $pdo->commit();

            Response::json(['id' => $productId], 200, 'Product updated successfully');

        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Failed to update product: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete / Archive a product (sellers can only remove their own product; admins can remove any)
     * DELETE /api/v1/buddy/products/{id}
     */
    public function destroy(Request $request, array $params): void
    {
        $sellerId  = $this->resolveSellerId($request);
        $user      = $request->getUser();
        $productId = (int)($params['id'] ?? 0);

        if ($productId <= 0) {
            Response::error('Invalid product ID.', 400);
        }

        try {
            $pdo = Database::getConnection();

            // Verify ownership
            $checkStmt = $pdo->prepare('SELECT id, seller_id FROM products WHERE id = :id LIMIT 1');
            $checkStmt->execute(['id' => $productId]);
            $existingProd = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existingProd) {
                Response::error('Product not found.', 404);
            }

            if ($sellerId !== null && (int)$existingProd['seller_id'] !== $sellerId) {
                Response::error('Forbidden. You do not have permission to delete products belonging to another seller.', 403);
            }

            // Check if product has existing customer order items
            $orderCheck = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE product_id = :id');
            $orderCheck->execute(['id' => $productId]);
            $hasOrders = ((int)$orderCheck->fetchColumn()) > 0;

            if ($hasOrders) {
                // Archive product to preserve customer purchase history & financial audit trails
                $archiveSql = "UPDATE products SET status = 'archived', stock_quantity = 0 WHERE id = :id";
                $archiveBinds = ['id' => $productId];
                if ($sellerId !== null) {
                    $archiveSql .= ' AND seller_id = :seller_id';
                    $archiveBinds['seller_id'] = $sellerId;
                }
                $stmt = $pdo->prepare($archiveSql);
                $stmt->execute($archiveBinds);

                Response::json(['id' => $productId, 'archived' => true], 200, 'Product has existing customer orders and has been archived successfully.');
            } else {
                // Hard delete product (cascades to product_images, reviews, cart, wishlist)
                $delSql = 'DELETE FROM products WHERE id = :id';
                $delBinds = ['id' => $productId];
                if ($sellerId !== null) {
                    $delSql .= ' AND seller_id = :seller_id';
                    $delBinds['seller_id'] = $sellerId;
                }
                $stmt = $pdo->prepare($delSql);
                $stmt->execute($delBinds);

                Response::json(['id' => $productId, 'deleted' => true], 200, 'Product deleted successfully');
            }

        } catch (Throwable $e) {
            Response::error('Failed to delete product: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Upload product images (multipart/form-data)
     * POST /api/v1/buddy/products/upload-image
     * Or POST /api/v1/buddy/products/{id}/images
     */
    public function uploadImages(Request $request, array $params = []): void
    {
        $sellerId = $this->resolveSellerId($request);
        $productId = isset($params['id']) && (int)$params['id'] > 0 ? (int)$params['id'] : (int)($request->get('product_id', 0));
        $isPrimaryParam = $request->get('is_primary');

        try {
            $pdo = Database::getConnection();

            // If product_id is specified, verify ownership
            if ($productId > 0) {
                $chkStmt = $pdo->prepare('SELECT id, seller_id FROM products WHERE id = :id LIMIT 1');
                $chkStmt->execute(['id' => $productId]);
                $prod = $chkStmt->fetch(PDO::FETCH_ASSOC);

                if (!$prod) {
                    Response::error('Product not found.', 404);
                }

                if ($sellerId !== null && (int)$prod['seller_id'] !== $sellerId) {
                    Response::error('Forbidden. You do not have permission to upload images for this product.', 403);
                }
            }

            // Inspect uploaded files
            $files = $request->getFiles();
            $uploadedFilesList = [];

            if (isset($files['image']) && is_array($files['image'])) {
                if (is_array($files['image']['name'])) {
                    // Multiple files uploaded as image[]
                    for ($i = 0; $i < count($files['image']['name']); $i++) {
                        if (!empty($files['image']['name'][$i])) {
                            $uploadedFilesList[] = [
                                'name'     => $files['image']['name'][$i],
                                'type'     => $files['image']['type'][$i] ?? '',
                                'tmp_name' => $files['image']['tmp_name'][$i],
                                'error'    => $files['image']['error'][$i],
                                'size'     => $files['image']['size'][$i],
                            ];
                        }
                    }
                } else {
                    $uploadedFilesList[] = $files['image'];
                }
            } elseif (isset($files['images']) && is_array($files['images'])) {
                if (is_array($files['images']['name'])) {
                    for ($i = 0; $i < count($files['images']['name']); $i++) {
                        if (!empty($files['images']['name'][$i])) {
                            $uploadedFilesList[] = [
                                'name'     => $files['images']['name'][$i],
                                'type'     => $files['images']['type'][$i] ?? '',
                                'tmp_name' => $files['images']['tmp_name'][$i],
                                'error'    => $files['images']['error'][$i],
                                'size'     => $files['images']['size'][$i],
                            ];
                        }
                    }
                } else {
                    $uploadedFilesList[] = $files['images'];
                }
            } else {
                // Check if any other file input was sent
                foreach ($files as $fileKey => $fileVal) {
                    if (is_array($fileVal) && isset($fileVal['tmp_name']) && !empty($fileVal['tmp_name'])) {
                        $uploadedFilesList[] = $fileVal;
                    }
                }
            }

            if (empty($uploadedFilesList)) {
                Response::error('No image files were provided for upload.', 422, ['image' => 'File is required']);
            }

            // Configure upload destination directory
            $uploadDir = ROOT_DIR . '/public/uploads/products';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            $maxFileSize = 5 * 1024 * 1024; // 5MB

            $savedImages = [];
            $errors = [];

            $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000';
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = "{$scheme}://{$host}";

            foreach ($uploadedFilesList as $index => $file) {
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = "Upload failed for file '{$file['name']}' with error code {$file['error']}.";
                    continue;
                }

                if ($file['size'] > $maxFileSize) {
                    $errors[] = "File '{$file['name']}' exceeds the maximum allowed size of 5MB.";
                    continue;
                }

                // Verify MIME type safely using finfo or getimagesize
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!in_array($mimeType, $allowedMimes, true)) {
                    $errors[] = "File '{$file['name']}' has invalid type ($mimeType). Allowed: JPG, PNG, WEBP, GIF.";
                    continue;
                }

                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExtensions, true)) {
                    $ext = ($mimeType === 'image/png') ? 'png' : (($mimeType === 'image/webp') ? 'webp' : 'jpg');
                }

                // Generate secure non-colliding filename
                $safeName = 'prod_' . bin2hex(random_bytes(10)) . '_' . time() . '.' . $ext;
                $destination = $uploadDir . '/' . $safeName;

                if (!move_uploaded_file($file['tmp_name'], $destination)) {
                    $errors[] = "Failed to store uploaded file '{$file['name']}'.";
                    continue;
                }

                $relativeUrl = '/uploads/products/' . $safeName;
                $fullUrl = $baseUrl . $relativeUrl;
                $isPrimary = ($index === 0 && ($isPrimaryParam === null || !empty($isPrimaryParam))) ? 1 : 0;

                $imageRecordId = null;
                // If productId is provided, persist immediately into product_images table
                if ($productId > 0) {
                    // Check if this is the first image for the product
                    $existingCount = (int)$pdo->query("SELECT COUNT(*) FROM product_images WHERE product_id = {$productId}")->fetchColumn();
                    if ($existingCount === 0) {
                        $isPrimary = 1;
                    }

                    $insertImg = $pdo->prepare(
                        'INSERT INTO product_images (product_id, image_url, is_primary, sort_order)
                         VALUES (:pid, :url, :is_prim, :sort)'
                    );
                    $insertImg->execute([
                        'pid'     => $productId,
                        'url'     => $fullUrl,
                        'is_prim' => $isPrimary,
                        'sort'    => $existingCount + $index,
                    ]);
                    $imageRecordId = (int)$pdo->lastInsertId();
                }

                $savedImages[] = [
                    'id'           => $imageRecordId,
                    'product_id'   => $productId > 0 ? $productId : null,
                    'file_name'    => $safeName,
                    'url'          => $fullUrl,
                    'relative_url' => $relativeUrl,
                    'is_primary'   => (bool)$isPrimary,
                    'size'         => $file['size'],
                    'mime_type'    => $mimeType,
                ];
            }

            if (empty($savedImages) && !empty($errors)) {
                Response::error('Failed to upload image(s).', 422, ['files' => $errors]);
            }

            Response::json([
                'uploaded' => $savedImages,
                'count'    => count($savedImages),
                'errors'   => $errors,
            ], 201, 'Product image(s) uploaded successfully');

        } catch (Throwable $e) {
            Response::error('Failed to process image upload: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Admin review action on a product (Approve, Reject, or Request Changes)
     * POST|PUT /api/v1/buddy/products/{id}/review
     * Payload: {
     *   action: 'approve' | 'reject' | 'publish',
     *   notes?: string,
     *   auto_publish?: bool
     * }
     */
    public function reviewProduct(Request $request, array $params): void
    {
        $user = $request->getUser();
        if (!$user || $user['role'] !== 'admin') {
            Response::error('Forbidden. Only administrators can perform product compliance review actions.', 403);
        }

        $productId = (int)($params['id'] ?? 0);
        if ($productId <= 0) {
            Response::error('Invalid product ID.', 400);
        }

        $action = trim((string)$request->get('action', ''));
        $notes  = trim((string)$request->get('notes', ''));
        $autoPublish = (bool)$request->get('auto_publish', false);

        if (!in_array($action, ['approve', 'reject', 'publish'], true)) {
            Response::error("Invalid review action. Allowed: 'approve', 'reject', 'publish'.", 422, [
                'action' => "Allowed actions: 'approve', 'reject', 'publish'"
            ]);
        }

        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare('SELECT p.*, sp.store_name FROM products p LEFT JOIN seller_profiles sp ON p.seller_id = sp.user_id WHERE p.id = :id LIMIT 1');
            $stmt->execute(['id' => $productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                Response::error('Product not found.', 404);
            }

            $newStatus = 'approved';
            if ($action === 'reject') {
                $newStatus = 'rejected';
            } elseif ($action === 'publish' || ($action === 'approve' && $autoPublish)) {
                $newStatus = 'published';
            }

            $reviewedAt = date('Y-m-d H:i:s');
            $reviewedBy = (int)$user['id'];

            $pdo->beginTransaction();

            $upd = $pdo->prepare(
                'UPDATE products
                 SET status = :status,
                     review_notes = :notes,
                     reviewed_by = :reviewer,
                     reviewed_at = :reviewed_at
                 WHERE id = :id'
            );
            $upd->execute([
                'status'      => $newStatus,
                'notes'       => !empty($notes) ? $notes : ($action === 'approve' ? 'Product approved for marketplace catalog.' : 'Compliance review rejected.'),
                'reviewer'    => $reviewedBy,
                'reviewed_at' => $reviewedAt,
                'id'          => $productId,
            ]);

            // Insert system audit log if table exists
            try {
                $auditStmt = $pdo->prepare(
                    'INSERT INTO audit_logs (actor_id, actor_role, actor_name, action, target_type, target_id, details)
                     VALUES (:aid, :arole, :aname, :action, :ttype, :tid, :details)'
                );
                $auditStmt->execute([
                    'aid'     => $reviewedBy,
                    'arole'   => 'admin',
                    'aname'   => $user['email'] ?? 'System Admin',
                    'action'  => 'PRODUCT_' . strtoupper($action),
                    'ttype'   => 'product',
                    'tid'     => (string)$productId,
                    'details' => json_encode([
                        'title'        => $product['title'],
                        'seller_id'    => $product['seller_id'],
                        'store_name'   => $product['store_name'],
                        'old_status'   => $product['status'],
                        'new_status'   => $newStatus,
                        'review_notes' => $notes,
                    ]),
                ]);
            } catch (Throwable $e) {
                // Table might not exist or non-blocking
            }

            $pdo->commit();

            Response::json([
                'product_id'   => $productId,
                'title'        => $product['title'],
                'seller_id'    => (int)$product['seller_id'],
                'status'       => $newStatus,
                'review_notes' => $notes,
                'reviewed_by'  => $reviewedBy,
                'reviewer_name'=> $user['full_name'] ?? 'Compliance Admin',
                'reviewed_at'  => $reviewedAt,
            ], 200, "Product has been successfully " . ($newStatus === 'published' ? 'published' : ($newStatus === 'approved' ? 'approved' : 'rejected')));

        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Failed to review product: ' . $e->getMessage(), 500);
        }
    }

    private function slugify(string $text): string
    {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text) ?: $text;
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = preg_replace('~-+~', '-', $text);
        $text = strtolower($text);
        return $text === '' ? 'product-' . time() : $text;
    }
}
