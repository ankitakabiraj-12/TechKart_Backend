<?php

declare(strict_types=1);

namespace App\Controllers\Customer;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Controller handling public Customer Catalog (Products, Master Catalog, Categories, Brands, Variants & Specs)
 */
class CustomerCatalogController
{
    /**
     * List active categories (hierarchical or flat)
     * GET /api/v1/customer/categories
     */
    public function categories(Request $request): void
    {
        try {
            $pdo = Database::getConnection();
            $parentId = $request->getQuery('parent_id');
            $nested = $request->getQuery('nested');

            if ($nested === '1' || $nested === 'true') {
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
                'SELECT c.id, c.parent_id, c.name, c.slug, c.image_url, c.display_order, c.created_at,
                        (SELECT COUNT(*) FROM products p WHERE p.subcategory_id = c.id AND (p.status = \'published\' OR p.status = \'approved\')) AS product_count
                 FROM categories c
                 WHERE c.parent_id = :pid AND c.is_active = 1
                 ORDER BY c.display_order ASC, c.id ASC'
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
     * List all verified Brands (optionally filtered by subcategory_id or category_id)
     * GET /api/v1/customer/brands
     */
    public function brands(Request $request): void
    {
        try {
            $pdo = Database::getConnection();
            $subcategoryId = $request->getQuery('subcategory_id');
            $categoryId    = $request->getQuery('category_id');

            if ($subcategoryId !== null && $subcategoryId !== '') {
                $stmt = $pdo->prepare(
                    'SELECT b.id, b.brand_name, b.brand_slug, b.official_website, b.brand_logo, b.verification_status,
                            scb.product_families
                     FROM brands b
                     JOIN subcategory_brands scb ON scb.brand_id = b.id
                     WHERE scb.subcategory_id = :sub_id AND b.verification_status = "verified"
                     ORDER BY b.brand_name ASC'
                );
                $stmt->execute(['sub_id' => (int)$subcategoryId]);
                $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
                Response::json($brands, 200, 'Subcategory brands retrieved successfully');
                return;
            }

            if ($categoryId !== null && $categoryId !== '') {
                $stmt = $pdo->prepare(
                    'SELECT DISTINCT b.id, b.brand_name, b.brand_slug, b.official_website, b.brand_logo, b.verification_status
                     FROM brands b
                     JOIN subcategory_brands scb ON scb.brand_id = b.id
                     JOIN categories c ON scb.subcategory_id = c.id
                     WHERE c.parent_id = :cat_id AND b.verification_status = "verified"
                     ORDER BY b.brand_name ASC'
                );
                $stmt->execute(['cat_id' => (int)$categoryId]);
                $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
                Response::json($brands, 200, 'Category brands retrieved successfully');
                return;
            }

            $stmt = $pdo->query('SELECT id, brand_name, brand_slug, official_website, brand_logo, verification_status FROM brands WHERE verification_status = "verified" ORDER BY brand_name ASC');
            $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
            Response::json($brands, 200, 'Brands retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to retrieve brands: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get brands relevant to a specific subcategory
     * GET /api/v1/customer/subcategories/{id}/brands
     */
    public function subcategoryBrands(Request $request, array $params = []): void
    {
        $subId = (int)($params['id'] ?? $request->getQuery('subcategory_id', 0));
        if ($subId <= 0) {
            Response::error('Valid subcategory ID required', 400);
            return;
        }

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT b.id, b.brand_name, b.brand_slug, b.official_website, b.brand_logo, b.verification_status,
                        scb.product_families
                 FROM brands b
                 JOIN subcategory_brands scb ON scb.brand_id = b.id
                 WHERE scb.subcategory_id = :sub_id AND b.verification_status = "verified"
                 ORDER BY b.brand_name ASC'
            );
            $stmt->execute(['sub_id' => $subId]);
            $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::json($brands, 200, 'Subcategory brands retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to retrieve subcategory brands: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get product types / verticals for a specific subcategory
     * GET /api/v1/customer/subcategories/{id}/product-types
     */
    public function subcategoryProductTypes(Request $request, array $params = []): void
    {
        $subId = (int)($params['id'] ?? $request->getQuery('subcategory_id', 0));
        if ($subId <= 0) {
            Response::error('Valid subcategory ID required', 400);
            return;
        }

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT id, subcategory_id, type_name, type_slug
                 FROM product_types
                 WHERE subcategory_id = :sub_id
                 ORDER BY type_name ASC'
            );
            $stmt->execute(['sub_id' => $subId]);
            $types = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::json($types, 200, 'Subcategory product types retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to retrieve product types: ' . $e->getMessage(), 500);
        }
    }

    /**
     * List published products with filtering, search, sorting, and pagination
     * GET /api/v1/customer/products
     */
    public function index(Request $request): void
    {
        try {
            $pdo = Database::getConnection();

            $search          = trim((string)$request->getQuery('search', ''));
            $categoryId      = $request->getQuery('category_id');
            $subcategoryId   = $request->getQuery('subcategory_id');
            $brandId         = $request->getQuery('brand_id');
            $productTypeId   = $request->getQuery('product_type_id');
            $brandSlug       = trim((string)$request->getQuery('brand', ''));
            $categorySlug    = trim((string)$request->getQuery('category', ''));
            $subcategorySlug = trim((string)$request->getQuery('subcategory', ''));
            $minPrice        = $request->getQuery('min_price');
            $maxPrice        = $request->getQuery('max_price');
            $inStock         = $request->getQuery('in_stock');
            $sortBy          = (string)$request->getQuery('sort_by', 'latest');

            $page  = max(1, (int)$request->getQuery('page', 1));
            $limit = min(100, max(1, (int)$request->getQuery('limit', 20)));
            $offset = ($page - 1) * $limit;

            $conditions = ["(p.status = 'published' OR p.status = 'approved')", "u.is_active = 1"];
            $params = [];

            if ($search !== '') {
                $conditions[] = '(p.title LIKE :search OR p.description LIKE :search_desc OR b.brand_name LIKE :search_brand OR mp.model_number LIKE :search_model)';
                $params['search'] = '%' . $search . '%';
                $params['search_desc'] = '%' . $search . '%';
                $params['search_brand'] = '%' . $search . '%';
                $params['search_model'] = '%' . $search . '%';
            }

            if ($categoryId !== null && $categoryId !== '') {
                $conditions[] = 'p.category_id = :category_id';
                $params['category_id'] = (int)$categoryId;
            }

            if ($subcategoryId !== null && $subcategoryId !== '') {
                $conditions[] = 'p.subcategory_id = :subcategory_id';
                $params['subcategory_id'] = (int)$subcategoryId;
            }

            if ($productTypeId !== null && $productTypeId !== '') {
                $conditions[] = '(p.product_type_id = :product_type_id OR mp.product_type_id = :product_type_id_mp)';
                $params['product_type_id'] = (int)$productTypeId;
                $params['product_type_id_mp'] = (int)$productTypeId;
            }

            if ($brandId !== null && $brandId !== '') {
                $conditions[] = 'p.brand_id = :brand_id';
                $params['brand_id'] = (int)$brandId;
            }

            if ($brandSlug !== '') {
                $conditions[] = 'b.brand_slug = :brand_slug';
                $params['brand_slug'] = $brandSlug;
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

            $orderBy = match ($sortBy) {
                'price_asc'  => 'p.price ASC, p.id DESC',
                'price_desc' => 'p.price DESC, p.id DESC',
                'title_asc'  => 'p.title ASC',
                'title_desc' => 'p.title DESC',
                'oldest'     => 'COALESCE(p.published_at, p.created_at) ASC, p.id ASC',
                default      => 'COALESCE(p.published_at, p.created_at) DESC, p.id DESC',
            };

            $countSql = "SELECT COUNT(p.id) AS total
                         FROM products p
                         JOIN users u ON p.seller_id = u.id
                         LEFT JOIN categories c ON p.category_id = c.id
                         LEFT JOIN categories sub ON p.subcategory_id = sub.id
                         LEFT JOIN brands b ON p.brand_id = b.id
                         LEFT JOIN master_products mp ON p.master_product_id = mp.id
                         WHERE {$whereSql}";
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $sql = "SELECT
                        p.id,
                        p.seller_id,
                        p.category_id,
                        p.subcategory_id,
                        p.master_product_id,
                        p.variant_id,
                        p.brand_id,
                        p.title,
                        p.slug,
                        p.description,
                        p.price,
                        p.mrp,
                        p.stock_quantity,
                        p.status,
                        p.published_at,
                        p.created_at,
                        p.updated_at,
                        b.brand_name,
                        b.brand_slug,
                        mp.model_number,
                        mp.warranty_information,
                        mp.country_of_origin,
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
                    LEFT JOIN brands b ON p.brand_id = b.id
                    LEFT JOIN master_products mp ON p.master_product_id = mp.id
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
     * Get single published product details with full image gallery, category, seller,
     * available variants, and category-specific dynamic specifications
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
                        p.master_product_id,
                        p.variant_id,
                        p.brand_id,
                        p.title,
                        p.slug,
                        p.description,
                        p.price,
                        p.mrp,
                        p.stock_quantity,
                        p.status,
                        p.published_at,
                        p.created_at,
                        p.updated_at,
                        b.brand_name,
                        b.brand_slug,
                        b.official_website AS brand_website,
                        b.brand_logo,
                        mp.model_number,
                        mp.manufacturer,
                        mp.country_of_origin,
                        mp.warranty_information,
                        mp.gtin_upc_ean,
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
                    LEFT JOIN brands b ON p.brand_id = b.id
                    LEFT JOIN master_products mp ON p.master_product_id = mp.id
                    LEFT JOIN seller_profiles sp ON p.seller_id = sp.user_id
                    WHERE " . ($isNumeric ? '(p.id = :id OR p.slug = :slug)' : 'p.slug = :slug') . "
                      AND (p.status = 'published' OR p.status = 'approved') AND u.is_active = 1
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
            $masterId = $product['master_product_id'] ? (int)$product['master_product_id'] : null;

            // Fetch product images
            $imgStmt = $pdo->prepare(
                'SELECT id, variant_id, image_url, is_primary, sort_order, image_type
                 FROM product_images
                 WHERE product_id = :product_id
                 ORDER BY is_primary DESC, sort_order ASC, id ASC'
            );
            $imgStmt->execute(['product_id' => $id]);
            $images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch real variants if master_product_id exists
            $variants = [];
            if ($masterId !== null) {
                $varStmt = $pdo->prepare(
                    'SELECT pv.id, pv.product_id, pv.variant_name, pv.sku, pv.colour, pv.ram, pv.storage, pv.configuration, pv.variant_attributes,
                            spl.id AS listing_id, spl.mrp, spl.selling_price, spl.stock_quantity, spl.dispatch_information, spl.shipping_information, spl.listing_status
                     FROM product_variants pv
                     LEFT JOIN seller_product_listings spl ON spl.variant_id = pv.id AND spl.listing_status = "active"
                     WHERE pv.product_id = :mpid AND pv.variant_status = "active"
                     ORDER BY pv.id ASC'
                );
                $varStmt->execute(['mpid' => $masterId]);
                $variants = $varStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($variants as &$v) {
                    $v['id'] = (int)$v['id'];
                    $v['mrp'] = $v['mrp'] !== null ? (float)$v['mrp'] : null;
                    $v['selling_price'] = $v['selling_price'] !== null ? (float)$v['selling_price'] : null;
                    $v['stock_quantity'] = (int)($v['stock_quantity'] ?? 0);
                    if (!empty($v['variant_attributes']) && is_string($v['variant_attributes'])) {
                        $v['variant_attributes'] = json_decode($v['variant_attributes'], true);
                    }
                }
                unset($v);
            }

            // Fetch category-specific dynamic specifications
            $specs = [];
            if ($masterId !== null) {
                $specStmt = $pdo->prepare(
                    'SELECT ca.attribute_name, ca.attribute_key, ca.unit, ps.attribute_value
                     FROM product_specifications ps
                     JOIN category_attributes ca ON ps.attribute_id = ca.id
                     WHERE ps.product_id = :mpid
                     ORDER BY ca.display_order ASC'
                );
                $specStmt->execute(['mpid' => $masterId]);
                $specs = $specStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $product['price'] = (float)$product['price'];
            $product['mrp'] = $product['mrp'] !== null ? (float)$product['mrp'] : null;
            $product['stock_quantity'] = (int)$product['stock_quantity'];
            $product['seller_is_verified'] = (int)($product['seller_is_verified'] ?? 0);
            $product['images'] = array_map(function ($img) {
                return [
                    'id'         => (int)$img['id'],
                    'variant_id' => $img['variant_id'] ? (int)$img['variant_id'] : null,
                    'image_url'  => $img['image_url'],
                    'is_primary' => (int)$img['is_primary'] === 1,
                    'sort_order' => (int)$img['sort_order'],
                    'image_type' => $img['image_type'] ?? 'front',
                ];
            }, $images);

            $primaryImg = null;
            foreach ($product['images'] as $img) {
                if (!empty($img['is_primary'])) {
                    $primaryImg = $img['image_url'];
                    break;
                }
            }
            $product['primary_image_url'] = $primaryImg ?? ($product['images'][0]['image_url'] ?? null);

            $product['variants'] = $variants;
            $product['specifications'] = $specs;

            Response::json($product, 200, 'Product details retrieved successfully');

        } catch (Throwable $e) {
            Response::error('Failed to retrieve product details: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get product comparison data for 2-4 products
     * GET /api/v1/customer/products/compare?ids=12,19,101
     */
    public function compare(Request $request): void
    {
        $rawIds = trim((string)$request->getQuery('ids', ''));
        if ($rawIds === '') {
            Response::error('Product IDs parameter `ids` is required (e.g. ?ids=12,19)', 400);
            return;
        }

        $ids = array_filter(array_map('intval', explode(',', $rawIds)));
        if (empty($ids)) {
            Response::error('Valid numeric product IDs required', 400);
            return;
        }

        try {
            $pdo = Database::getConnection();
            $inQuery = implode(',', $ids);

            $stmt = $pdo->query(
                "SELECT p.id, p.title, p.price, p.mrp, p.stock_quantity, b.brand_name, mp.model_number,
                        mp.warranty_information, mp.country_of_origin, c.name AS category_name,
                        (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image_url
                 FROM products p
                 LEFT JOIN brands b ON p.brand_id = b.id
                 LEFT JOIN master_products mp ON p.master_product_id = mp.id
                 LEFT JOIN categories c ON p.category_id = c.id
                 WHERE p.id IN ({$inQuery})"
            );
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::json($products, 200, 'Comparison data retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to compare products: ' . $e->getMessage(), 500);
        }
    }
}
