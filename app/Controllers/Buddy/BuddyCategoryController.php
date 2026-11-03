<?php

declare(strict_types=1);

namespace App\Controllers\Buddy;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Controller handling Categories & Category-Specific Dynamic Specification Templates
 * for TechKart Buddy Seller and Admin Catalog Management
 */
class BuddyCategoryController
{
    /**
     * Category-specific specification schemas.
     * Maps category IDs/slugs to dynamic field definitions.
     */
    private static array $specificationSchemas = [
        // 1. Electronics (General)
        1 => [
            'category_name' => 'Electronics',
            'fields' => [
                ['key' => 'brand', 'label' => 'Brand / Manufacturer', 'type' => 'text', 'required' => true, 'default' => 'TechKart OEM'],
                ['key' => 'model', 'label' => 'Model Number', 'type' => 'text', 'required' => false, 'default' => ''],
                ['key' => 'warranty', 'label' => 'Warranty Coverage', 'type' => 'select', 'options' => ['6 Months Seller Warranty', '1 Year Official Brand Warranty', '2 Years Extended Warranty', 'No Warranty'], 'required' => true, 'default' => '1 Year Official Brand Warranty'],
                ['key' => 'power_source', 'label' => 'Power Source', 'type' => 'select', 'options' => ['Battery Powered', 'AC Adapter / Wall Plug', 'USB Powered', 'Solar / Hybrid'], 'required' => false, 'default' => 'USB Powered'],
                ['key' => 'origin', 'label' => 'Country of Origin / Edition', 'type' => 'select', 'options' => ['Official Domestic Edition', 'Imported Global Version', 'EU Standard Version'], 'required' => true, 'default' => 'Imported Global Version'],
            ],
        ],
        // 2. Wearables & Smartwatches
        2 => [
            'category_name' => 'Wearables & Smartwatches',
            'fields' => [
                ['key' => 'case_material', 'label' => 'Case Material', 'type' => 'select', 'options' => ['Grade 5 Titanium', 'Aerospace Aluminum', 'Stainless Steel', 'Ceramic & Polycarbonate'], 'required' => true, 'default' => 'Grade 5 Titanium'],
                ['key' => 'water_resistance', 'label' => 'Water Resistance', 'type' => 'select', 'options' => ['IP68 (1.5m / 30min)', '50m (5 ATM)', '100m (10 ATM)', '200m Diver Grade'], 'required' => true, 'default' => '100m (10 ATM)'],
                ['key' => 'connectivity', 'label' => 'Connectivity & GPS', 'type' => 'select', 'options' => ['BT 5.3 + Dual GPS', 'BT 5.2 Only', 'BT 5.3 + LTE eSIM', 'WiFi + BT 5.0'], 'required' => true, 'default' => 'BT 5.3 + Dual GPS'],
                ['key' => 'display', 'label' => 'Display Technology', 'type' => 'select', 'options' => ['3000-nit LTPO AMOLED', '1000-nit AMOLED', 'Retina OLED Always-On', 'TFT LCD Touch'], 'required' => true, 'default' => '3000-nit LTPO AMOLED'],
                ['key' => 'battery_life', 'label' => 'Battery Life', 'type' => 'select', 'options' => ['24-36 Hours Standard', '48-72 Hours Endurance', '7-14 Days Battery Saver', '30 Days Ultralight'], 'required' => true, 'default' => '48-72 Hours Endurance'],
                ['key' => 'warranty', 'label' => 'Warranty Coverage', 'type' => 'select', 'options' => ['1 Year Official Brand Warranty', '2 Years Extended Warranty', '6 Months Seller Warranty'], 'required' => true, 'default' => '1 Year Official Brand Warranty'],
            ],
        ],
        // 3. Audio & Studio Sound
        3 => [
            'category_name' => 'Audio & Studio Sound',
            'fields' => [
                ['key' => 'headphone_type', 'label' => 'Form Factor', 'type' => 'select', 'options' => ['Over-Ear Studio', 'On-Ear Portable', 'True Wireless Earbuds (TWS)', 'In-Ear Monitors (IEM)'], 'required' => true, 'default' => 'Over-Ear Studio'],
                ['key' => 'noise_cancelling', 'label' => 'Active Noise Cancellation', 'type' => 'select', 'options' => ['Hybrid ANC (-45dB)', 'Adaptive Smart ANC', 'Standard ANC', 'Passive Noise Isolation'], 'required' => true, 'default' => 'Hybrid ANC (-45dB)'],
                ['key' => 'driver_size', 'label' => 'Acoustic Driver Size', 'type' => 'text', 'required' => false, 'default' => '40mm Custom Titanium Drivers'],
                ['key' => 'battery_life', 'label' => 'Playtime / Battery', 'type' => 'select', 'options' => ['30 Hours with ANC', '40 Hours Extended', '60 Hours Ultra', '8 Hours Buds + 24h Case'], 'required' => true, 'default' => '40 Hours Extended'],
                ['key' => 'audio_codecs', 'label' => 'Hi-Res Audio Codecs', 'type' => 'select', 'options' => ['LDAC / aptX HD / AAC / SBC', 'aptX Adaptive / AAC', 'AAC / SBC Standard'], 'required' => false, 'default' => 'LDAC / aptX HD / AAC / SBC'],
                ['key' => 'warranty', 'label' => 'Warranty Coverage', 'type' => 'select', 'options' => ['1 Year Official Brand Warranty', '2 Years Extended Warranty', '6 Months Replacement'], 'required' => true, 'default' => '1 Year Official Brand Warranty'],
            ],
        ],
        // 4. Chargers & Power Delivery
        4 => [
            'category_name' => 'Chargers & Power Delivery',
            'fields' => [
                ['key' => 'max_wattage', 'label' => 'Maximum Output Wattage', 'type' => 'select', 'options' => ['20W Compact', '30W High-Speed', '65W GaN Fast Charger', '100W GaN Pro', '140W Multi-Port GaN'], 'required' => true, 'default' => '65W GaN Fast Charger'],
                ['key' => 'port_configuration', 'label' => 'Port Configuration', 'type' => 'select', 'options' => ['Dual USB-C (2C)', '2x USB-C + 1x USB-A (2C1A)', 'Single USB-C (1C)', '3x USB-C + 1x USB-A (3C1A)'], 'required' => true, 'default' => '2x USB-C + 1x USB-A (2C1A)'],
                ['key' => 'technology', 'label' => 'Charging Technology', 'type' => 'select', 'options' => ['GaN III Technology (Gallium Nitride)', 'GaN II Fast Charging', 'Standard Silicon PD 3.0', 'QC 4+ & PPS Protocol'], 'required' => true, 'default' => 'GaN III Technology (Gallium Nitride)'],
                ['key' => 'safety_certifications', 'label' => 'Safety Protections', 'type' => 'select', 'options' => ['ActiveShield 2.0 Temperature Monitor', 'Over-Voltage / Surge Protection', 'CE / FCC / RoHS Certified'], 'required' => false, 'default' => 'ActiveShield 2.0 Temperature Monitor'],
                ['key' => 'warranty', 'label' => 'Warranty Coverage', 'type' => 'select', 'options' => ['18 Months Brand Warranty', '1 Year Official Brand Warranty', '6 Months Replacement'], 'required' => true, 'default' => '18 Months Brand Warranty'],
            ],
        ],
        // 5. Flagship Smartphones
        5 => [
            'category_name' => 'Flagship Smartphones',
            'fields' => [
                ['key' => 'processor', 'label' => 'Processor / Chipset', 'type' => 'select', 'options' => ['Snapdragon 8 Gen 3', 'Apple A17 Pro / A18', 'Dimensity 9300', 'Google Tensor G3'], 'required' => true, 'default' => 'Snapdragon 8 Gen 3'],
                ['key' => 'display_size', 'label' => 'Display Size & Panel', 'type' => 'select', 'options' => ['6.8" Quad HD+ LTPO 120Hz', '6.7" OLED 120Hz ProMotion', '6.2" Compact Dynamic AMOLED', '7.9" Foldable Dual Screen'], 'required' => true, 'default' => '6.8" Quad HD+ LTPO 120Hz'],
                ['key' => 'camera_setup', 'label' => 'Camera System', 'type' => 'select', 'options' => ['200MP Main + 50MP Periscope + 12MP Ultra-Wide', '50MP Triple Studio Setup', '48MP Pro Fusion System'], 'required' => true, 'default' => '200MP Main + 50MP Periscope + 12MP Ultra-Wide'],
                ['key' => 'ram_storage', 'label' => 'RAM & Storage Configuration', 'type' => 'select', 'options' => ['12GB RAM / 256GB Storage', '16GB RAM / 512GB Storage', '16GB RAM / 1TB Storage', '8GB RAM / 128GB Storage'], 'required' => true, 'default' => '12GB RAM / 256GB Storage'],
                ['key' => 'battery_charging', 'label' => 'Battery & Fast Charging', 'type' => 'select', 'options' => ['5000mAh + 100W Wired + 50W Wireless', '4500mAh + 67W Fast Charging', '5400mAh + 80W SuperVOOC'], 'required' => true, 'default' => '5000mAh + 100W Wired + 50W Wireless'],
                ['key' => 'warranty', 'label' => 'Warranty Coverage', 'type' => 'select', 'options' => ['1 Year Official Manufacturer Warranty', '2 Years VIP Official Warranty'], 'required' => true, 'default' => '1 Year Official Manufacturer Warranty'],
            ],
        ],
        // 6. Keyboards & Peripherals
        6 => [
            'category_name' => 'Keyboards & Peripherals',
            'fields' => [
                ['key' => 'switch_type', 'label' => 'Key Switch Type', 'type' => 'select', 'options' => ['Hot-Swappable Mechanical Tactile', 'Linear Mechanical Red Switches', 'Clicky Blue Mechanical', 'Silent Magnetic Hall Effect'], 'required' => true, 'default' => 'Hot-Swappable Mechanical Tactile'],
                ['key' => 'connectivity', 'label' => 'Connectivity Modes', 'type' => 'select', 'options' => ['Tri-Mode (2.4GHz + BT 5.1 + USB-C)', 'Dual Wireless (2.4G + BT)', 'Detachable USB-C Wired Only'], 'required' => true, 'default' => 'Tri-Mode (2.4GHz + BT 5.1 + USB-C)'],
                ['key' => 'layout_size', 'label' => 'Layout & Form Factor', 'type' => 'select', 'options' => ['75% Compact (82 Keys)', '65% Ultra-Compact', 'TKL 80% (Tenkeyless)', '100% Full-Sized Keyboard'], 'required' => true, 'default' => '75% Compact (82 Keys)'],
                ['key' => 'backlight', 'label' => 'Backlighting & RGB', 'type' => 'select', 'options' => ['South-Facing RGB (16.8M Colors)', 'Per-Key Customizable RGB', 'Monochrome White LED', 'Non-Backlit'], 'required' => false, 'default' => 'South-Facing RGB (16.8M Colors)'],
                ['key' => 'battery_life', 'label' => 'Battery Capacity', 'type' => 'select', 'options' => ['4000mAh Rechargeable (200h)', '2000mAh (100h)', 'Battery-Free (Wired)'], 'required' => false, 'default' => '4000mAh Rechargeable (200h)'],
                ['key' => 'warranty', 'label' => 'Warranty Coverage', 'type' => 'select', 'options' => ['1 Year Official Brand Warranty', '2 Years Warranty'], 'required' => true, 'default' => '1 Year Official Brand Warranty'],
            ],
        ],
        // 7. Cables & Adapters
        7 => [
            'category_name' => 'Cables & Adapters',
            'fields' => [
                ['key' => 'cable_type', 'label' => 'Connector Type', 'type' => 'select', 'options' => ['USB-C to USB-C', 'USB-C to Lightning (MFi)', 'USB-A to USB-C', 'HDMI 2.1 Ultra High Speed'], 'required' => true, 'default' => 'USB-C to USB-C'],
                ['key' => 'power_rating', 'label' => 'Power Delivery Rating', 'type' => 'select', 'options' => ['100W PD 5A E-Marker', '240W EPR Ultra High Speed', '60W PD Fast Charging', '15W Standard Charging'], 'required' => true, 'default' => '100W PD 5A E-Marker'],
                ['key' => 'data_transfer', 'label' => 'Data Transfer Speed', 'type' => 'select', 'options' => ['USB 2.0 (480 Mbps)', 'USB 3.2 Gen 2 (10 Gbps)', 'USB4 / Thunderbolt 4 (40 Gbps)'], 'required' => false, 'default' => 'USB 2.0 (480 Mbps)'],
                ['key' => 'cable_length', 'label' => 'Cable Length', 'type' => 'select', 'options' => ['1.0 Meter (3.3 ft)', '1.8 Meters (6.0 ft)', '2.0 Meters (6.6 ft)', '0.5 Meter Compact'], 'required' => true, 'default' => '1.0 Meter (3.3 ft)'],
                ['key' => 'material_braiding', 'label' => 'Braiding & Durability', 'type' => 'select', 'options' => ['Double-Braided Nylon (25,000+ Bends)', 'Bio-Braided Silicone', 'TPE Eco-Friendly'], 'required' => false, 'default' => 'Double-Braided Nylon (25,000+ Bends)'],
                ['key' => 'warranty', 'label' => 'Warranty Coverage', 'type' => 'select', 'options' => ['Lifetime Warranty', '1 Year Replacement Warranty', '6 Months Seller Warranty'], 'required' => true, 'default' => '1 Year Replacement Warranty'],
            ],
        ],
    ];

    /**
     * List all active categories for Buddy Seller/Admin product creation
     * GET /api/v1/buddy/categories
     * Query:
     * - parent_id: int | 'root' | 'all' (default is 'root' - returns 45 main categories)
     * - nested: 1 or true (returns 45 root categories with their subcategories array)
     */
    public function index(Request $request): void
    {
        try {
            $pdo = Database::getConnection();
            $parentId = $request->getQuery('parent_id', 'root');
            $nested   = $request->getQuery('nested');

            if ($nested === '1' || $nested === 'true') {
                $rootStmt = $pdo->prepare(
                    'SELECT c.id, c.parent_id, c.name, c.slug, c.image_url, c.display_order, c.is_active,
                            (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS products_count
                     FROM categories c
                     WHERE c.parent_id IS NULL AND c.is_active = 1
                     ORDER BY c.display_order ASC, c.id ASC'
                );
                $rootStmt->execute();
                $roots = $rootStmt->fetchAll(PDO::FETCH_ASSOC);

                $childStmt = $pdo->prepare(
                    'SELECT c.id, c.parent_id, c.name, c.slug, c.image_url, c.display_order, c.is_active,
                            (SELECT COUNT(*) FROM products p WHERE p.subcategory_id = c.id) AS products_count
                     FROM categories c
                     WHERE c.parent_id IS NOT NULL AND c.is_active = 1
                     ORDER BY c.display_order ASC, c.id ASC'
                );
                $childStmt->execute();
                $allChildren = $childStmt->fetchAll(PDO::FETCH_ASSOC);

                $childrenByParent = [];
                foreach ($allChildren as $child) {
                    $pid = (int)$child['parent_id'];
                    $childrenByParent[$pid][] = [
                        'id' => (int)$child['id'],
                        'parent_id' => $pid,
                        'name' => $child['name'],
                        'slug' => $child['slug'],
                        'image_url' => $child['image_url'],
                        'display_order' => (int)$child['display_order'],
                        'is_active' => (int)$child['is_active'],
                        'products_count' => (int)$child['products_count'],
                    ];
                }

                foreach ($roots as &$cat) {
                    $catId = (int)$cat['id'];
                    $cat['id'] = $catId;
                    $cat['parent_id'] = null;
                    $cat['display_order'] = (int)$cat['display_order'];
                    $cat['products_count'] = (int)$cat['products_count'];
                    $cat['has_spec_template'] = isset(self::$specificationSchemas[$catId]);
                    $cat['spec_fields_count'] = isset(self::$specificationSchemas[$catId])
                        ? count(self::$specificationSchemas[$catId]['fields'])
                        : 0;
                    $cat['subcategories'] = $childrenByParent[$catId] ?? [];
                }
                unset($cat);

                Response::json($roots, 200, 'Categories retrieved successfully');
                return;
            }

            $conditions = ['c.is_active = 1'];
            $params = [];

            if ($parentId === 'root' || $parentId === 'null' || $parentId === '0') {
                $conditions[] = 'c.parent_id IS NULL';
            } elseif ($parentId !== 'all') {
                $conditions[] = 'c.parent_id = :parent_id';
                $params['parent_id'] = (int)$parentId;
            }

            $whereSql = implode(' AND ', $conditions);

            $stmt = $pdo->prepare(
                "SELECT c.id, c.parent_id, c.name, c.slug, c.image_url, c.display_order, c.is_active,
                        (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id OR p.subcategory_id = c.id) AS products_count
                 FROM categories c
                 WHERE {$whereSql}
                 ORDER BY c.display_order ASC, c.id ASC"
            );
            $stmt->execute($params);
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Enrich with specification schema metadata
            foreach ($categories as &$cat) {
                $catId = (int)$cat['id'];
                $cat['id'] = $catId;
                $cat['parent_id'] = $cat['parent_id'] !== null ? (int)$cat['parent_id'] : null;
                $cat['display_order'] = (int)$cat['display_order'];
                $cat['products_count'] = (int)$cat['products_count'];
                $cat['has_spec_template'] = isset(self::$specificationSchemas[$catId]);
                $cat['spec_fields_count'] = isset(self::$specificationSchemas[$catId])
                    ? count(self::$specificationSchemas[$catId]['fields'])
                    : 0;
            }
            unset($cat);

            Response::json($categories, 200, 'Categories retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to retrieve categories: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get subcategories for a given parent category ID
     * GET /api/v1/buddy/categories/{id}/subcategories
     */
    public function subcategories(Request $request, array $params = []): void
    {
        $parentId = (int)($params['id'] ?? $request->getQuery('parent_id', 0));
        if ($parentId <= 0) {
            Response::error('Invalid parent category ID.', 400);
            return;
        }

        try {
            $pdo = Database::getConnection();
            $pStmt = $pdo->prepare('SELECT id, parent_id, name, slug, image_url, display_order FROM categories WHERE id = :id AND is_active = 1 LIMIT 1');
            $pStmt->execute(['id' => $parentId]);
            $parent = $pStmt->fetch(PDO::FETCH_ASSOC);

            if (!$parent) {
                Response::error('Category not found or inactive.', 404);
                return;
            }

            $stmt = $pdo->prepare(
                'SELECT c.id, c.parent_id, c.name, c.slug, c.image_url, c.display_order, c.is_active,
                        (SELECT COUNT(*) FROM products p WHERE p.subcategory_id = c.id) AS products_count
                 FROM categories c
                 WHERE c.parent_id = :pid AND c.is_active = 1
                 ORDER BY c.display_order ASC, c.id ASC'
            );
            $stmt->execute(['pid' => $parentId]);
            $subcategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($subcategories as &$sub) {
                $sub['id'] = (int)$sub['id'];
                $sub['parent_id'] = (int)$sub['parent_id'];
                $sub['display_order'] = (int)$sub['display_order'];
                $sub['products_count'] = (int)$sub['products_count'];
            }
            unset($sub);

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
     * Get specific category details along with its dynamic specification schema
     * GET /api/v1/buddy/categories/{id}
     */
    public function show(Request $request, array $params): void
    {
        $id = isset($params['id']) ? (int)$params['id'] : 0;
        if ($id <= 0) {
            Response::error('Invalid category ID.', 400);
        }

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT c.id, c.parent_id, c.name, c.slug, c.image_url, c.is_active,
                        (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS products_count
                 FROM categories c
                 WHERE c.id = :id AND c.is_active = 1
                 LIMIT 1'
            );
            $stmt->execute(['id' => $id]);
            $category = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$category) {
                Response::error('Category not found or inactive.', 404);
            }

            $category['id'] = (int)$category['id'];
            $category['parent_id'] = $category['parent_id'] !== null ? (int)$category['parent_id'] : null;
            $category['products_count'] = (int)$category['products_count'];

            // Attach dynamic specification schema
            $schema = self::$specificationSchemas[$id] ?? self::$specificationSchemas[1];
            $category['specifications_schema'] = $schema;

            Response::json($category, 200, 'Category details retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to retrieve category details: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get dynamic specification template for a given category
     * GET /api/v1/buddy/categories/{id}/specifications
     */
    public function specifications(Request $request, array $params): void
    {
        $categoryId = isset($params['id']) ? (int)$params['id'] : 0;
        if ($categoryId <= 0) {
            Response::error('Invalid category ID.', 400);
        }

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('SELECT id, name, slug FROM categories WHERE id = :id AND is_active = 1 LIMIT 1');
            $stmt->execute(['id' => $categoryId]);
            $category = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$category) {
                Response::error('Category not found.', 404);
            }

            $schema = self::$specificationSchemas[$categoryId] ?? [
                'category_name' => $category['name'],
                'fields' => self::$specificationSchemas[1]['fields'],
            ];

            // Build default initial key-value map for ease of use by frontend
            $defaults = [];
            foreach ($schema['fields'] as $field) {
                $defaults[$field['key']] = $field['default'] ?? '';
            }

            Response::json([
                'category_id'   => (int)$category['id'],
                'category_name' => $category['name'],
                'category_slug' => $category['slug'],
                'schema'        => $schema['fields'],
                'defaults'      => $defaults,
            ], 200, 'Category specification schema retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to retrieve specification template: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Helper to resolve authenticated user
     */
    private function resolveUser(Request $request): array
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('Unauthorized. Authentication required.', 401);
        }
        return $user;
    }

    /**
     * Admin Paginated Categories Directory with Search & Filtering
     * GET /api/v1/buddy/admin/categories
     * Query: page, limit, search, status, parent_id
     */
    public function adminCategories(Request $request): void
    {
        $user = $this->resolveUser($request);
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden. Administrators only.', 403);
        }

        $search   = trim((string)$request->getQuery('search', ''));
        $status   = strtolower(trim((string)$request->getQuery('status', 'all')));
        $parentId = $request->getQuery('parent_id');
        $page     = max(1, (int)$request->getQuery('page', 1));
        $limit    = min(100, max(1, (int)$request->getQuery('limit', 20)));
        $offset   = ($page - 1) * $limit;

        try {
            $pdo = Database::getConnection();

            $conditions = ['1=1'];
            $params = [];

            if ($status === 'active') {
                $conditions[] = 'c.is_active = 1';
            } elseif ($status === 'inactive') {
                $conditions[] = 'c.is_active = 0';
            }

            if ($parentId !== null && $parentId !== '') {
                if ($parentId === 'root' || $parentId === '0' || $parentId === 'null') {
                    $conditions[] = 'c.parent_id IS NULL';
                } else {
                    $conditions[] = 'c.parent_id = :p_id';
                    $params['p_id'] = (int)$parentId;
                }
            }

            if ($search !== '') {
                $conditions[] = '(c.name LIKE :s_name OR c.slug LIKE :s_slug)';
                $searchTerm = '%' . $search . '%';
                $params['s_name'] = $searchTerm;
                $params['s_slug'] = $searchTerm;
            }

            $whereSql = implode(' AND ', $conditions);

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM categories c WHERE {$whereSql}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $sql = "SELECT c.id, c.parent_id, c.name, c.slug, c.image_url, c.display_order, c.is_active, c.created_at,
                           p.name AS parent_name,
                           (SELECT COUNT(*) FROM products prod WHERE prod.category_id = c.id) AS products_count,
                           (SELECT COUNT(*) FROM categories child WHERE child.parent_id = c.id) AS children_count
                    FROM categories c
                    LEFT JOIN categories p ON c.parent_id = p.id
                    WHERE {$whereSql}
                    ORDER BY c.display_order ASC, c.id ASC
                    LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($categories as &$cat) {
                $catId = (int)$cat['id'];
                $cat['id'] = $catId;
                $cat['parent_id'] = $cat['parent_id'] !== null ? (int)$cat['parent_id'] : null;
                $cat['is_active'] = (int)$cat['is_active'];
                $cat['products_count'] = (int)$cat['products_count'];
                $cat['children_count'] = (int)$cat['children_count'];
                $cat['spec_fields_count'] = isset(self::$specificationSchemas[$catId])
                    ? count(self::$specificationSchemas[$catId]['fields'])
                    : 0;
            }
            unset($cat);

            // Metrics for admin overview
            $totalCategories = (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
            $totalRoots = (int)$pdo->query('SELECT COUNT(*) FROM categories WHERE parent_id IS NULL')->fetchColumn();
            $totalSubcategories = (int)$pdo->query('SELECT COUNT(*) FROM categories WHERE parent_id IS NOT NULL')->fetchColumn();
            $totalListings = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();

            Response::json([
                'metrics' => [
                    'total_categories'    => $totalCategories,
                    'total_roots'         => $totalRoots,
                    'total_subcategories' => $totalSubcategories,
                    'total_listings'      => $totalListings,
                    'version'             => 'v4.2 Published',
                ],
                'categories' => $categories,
                'pagination' => [
                    'total'       => $total,
                    'page'        => $page,
                    'limit'       => $limit,
                    'total_pages' => (int)ceil($total / max(1, $limit)),
                ],
            ], 200, 'Categories retrieved successfully');

        } catch (Throwable $e) {
            Response::error('Failed to retrieve categories: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Admin Create Category
     * POST /api/v1/buddy/admin/categories
     * Body: { name: string, slug?: string, parent_id?: int|null, image_url?: string, is_active?: int|bool }
     */
    public function createCategory(Request $request): void
    {
        $user = $this->resolveUser($request);
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden. Administrators only.', 403);
        }

        $name = trim((string)$request->get('name', ''));
        if ($name === '') {
            Response::error('Category name is required.', 422);
        }

        $slug = trim((string)$request->get('slug', ''));
        if ($slug === '') {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
        }

        $rawParentId = $request->get('parent_id');
        $parentId = ($rawParentId !== null && $rawParentId !== '' && (int)$rawParentId > 0) ? (int)$rawParentId : null;
        $imageUrl = trim((string)$request->get('image_url', ''));
        $isActive = $request->get('is_active') !== null ? (int)(bool)$request->get('is_active') : 1;

        try {
            $pdo = Database::getConnection();

            // Validate parent_id existence if provided
            if ($parentId !== null) {
                $checkParent = $pdo->prepare('SELECT id FROM categories WHERE id = :pid LIMIT 1');
                $checkParent->execute(['pid' => $parentId]);
                if (!$checkParent->fetch()) {
                    Response::error('Parent category does not exist.', 400);
                }
            }

            // Ensure unique slug
            $checkSlug = $pdo->prepare('SELECT id FROM categories WHERE slug = :slug LIMIT 1');
            $checkSlug->execute(['slug' => $slug]);
            if ($checkSlug->fetch()) {
                $slug = $slug . '-' . time();
            }

            $stmt = $pdo->prepare(
                'INSERT INTO categories (parent_id, name, slug, image_url, is_active, created_at)
                 VALUES (:parent_id, :name, :slug, :image_url, :is_active, NOW())'
            );
            $stmt->execute([
                'parent_id' => $parentId,
                'name'      => $name,
                'slug'      => $slug,
                'image_url' => $imageUrl !== '' ? $imageUrl : null,
                'is_active' => $isActive,
            ]);

            $newId = (int)$pdo->lastInsertId();

            Response::json([
                'id'        => $newId,
                'parent_id' => $parentId,
                'name'      => $name,
                'slug'      => $slug,
                'image_url' => $imageUrl !== '' ? $imageUrl : null,
                'is_active' => $isActive,
            ], 201, 'Category created successfully');

        } catch (Throwable $e) {
            Response::error('Failed to create category: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Admin Update Category
     * PUT/PATCH /api/v1/buddy/admin/categories/{id}
     * Body: { name?: string, slug?: string, parent_id?: int|null, image_url?: string, is_active?: int|bool }
     */
    public function updateCategory(Request $request, array $params = []): void
    {
        $user = $this->resolveUser($request);
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden. Administrators only.', 403);
        }

        $id = (int)($params['id'] ?? $request->get('id', 0));
        if ($id <= 0) {
            Response::error('Invalid category ID.', 400);
        }

        try {
            $pdo = Database::getConnection();

            $checkStmt = $pdo->prepare('SELECT id, parent_id, name, slug, image_url, is_active FROM categories WHERE id = :id LIMIT 1');
            $checkStmt->execute(['id' => $id]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                Response::error('Category not found.', 404);
            }

            $name = $request->get('name') !== null ? trim((string)$request->get('name')) : $existing['name'];
            if ($name === '') {
                Response::error('Category name cannot be empty.', 422);
            }

            $slug = $request->get('slug') !== null ? trim((string)$request->get('slug')) : $existing['slug'];
            if ($slug !== $existing['slug']) {
                $checkSlug = $pdo->prepare('SELECT id FROM categories WHERE slug = :slug AND id != :id LIMIT 1');
                $checkSlug->execute(['slug' => $slug, 'id' => $id]);
                if ($checkSlug->fetch()) {
                    Response::error('Category slug already in use.', 409);
                }
            }

            $parentId = $existing['parent_id'];
            if ($request->get('parent_id') !== null) {
                $rawP = $request->get('parent_id');
                if ($rawP === null || $rawP === '' || (int)$rawP === 0) {
                    $parentId = null;
                } else {
                    $pId = (int)$rawP;
                    if ($pId === $id) {
                        Response::error('Category cannot be its own parent.', 400);
                    }
                    $checkParent = $pdo->prepare('SELECT id FROM categories WHERE id = :pid LIMIT 1');
                    $checkParent->execute(['pid' => $pId]);
                    if (!$checkParent->fetch()) {
                        Response::error('Parent category does not exist.', 400);
                    }
                    $parentId = $pId;
                }
            }

            $imageUrl = $request->get('image_url') !== null ? trim((string)$request->get('image_url')) : $existing['image_url'];
            $isActive = $request->get('is_active') !== null ? (int)(bool)$request->get('is_active') : (int)$existing['is_active'];

            $upStmt = $pdo->prepare(
                'UPDATE categories
                 SET name = :name, slug = :slug, parent_id = :parent_id, image_url = :image_url, is_active = :is_active
                 WHERE id = :id'
            );
            $upStmt->execute([
                'name'      => $name,
                'slug'      => $slug,
                'parent_id' => $parentId,
                'image_url' => $imageUrl !== '' ? $imageUrl : null,
                'is_active' => $isActive,
                'id'        => $id,
            ]);

            Response::json([
                'id'        => $id,
                'parent_id' => $parentId,
                'name'      => $name,
                'slug'      => $slug,
                'image_url' => $imageUrl !== '' ? $imageUrl : null,
                'is_active' => $isActive,
            ], 200, 'Category updated successfully');

        } catch (Throwable $e) {
            Response::error('Failed to update category: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Admin Delete Category
     * DELETE /api/v1/buddy/admin/categories/{id}
     */
    public function deleteCategory(Request $request, array $params = []): void
    {
        $user = $this->resolveUser($request);
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden. Administrators only.', 403);
        }

        $id = (int)($params['id'] ?? $request->get('id', 0));
        if ($id <= 0) {
            Response::error('Invalid category ID.', 400);
        }

        try {
            $pdo = Database::getConnection();

            $checkStmt = $pdo->prepare('SELECT id, name FROM categories WHERE id = :id LIMIT 1');
            $checkStmt->execute(['id' => $id]);
            $category = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$category) {
                Response::error('Category not found.', 404);
            }

            // Check if products exist in category
            $prodCheck = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = :id');
            $prodCheck->execute(['id' => $id]);
            $prodCount = (int)$prodCheck->fetchColumn();

            if ($prodCount > 0) {
                Response::error("Cannot delete category with {$prodCount} active product(s). Reassign products first.", 400);
            }

            // Check if child categories exist
            $childCheck = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE parent_id = :id');
            $childCheck->execute(['id' => $id]);
            $childCount = (int)$childCheck->fetchColumn();

            if ($childCount > 0) {
                Response::error("Cannot delete category with {$childCount} subcategory(ies). Delete or reassign child nodes first.", 400);
            }

            $delStmt = $pdo->prepare('DELETE FROM categories WHERE id = :id');
            $delStmt->execute(['id' => $id]);

            Response::json([
                'id' => $id,
                'name' => $category['name'],
            ], 200, 'Category deleted successfully');

        } catch (Throwable $e) {
            Response::error('Failed to delete category: ' . $e->getMessage(), 500);
        }
    }
}
