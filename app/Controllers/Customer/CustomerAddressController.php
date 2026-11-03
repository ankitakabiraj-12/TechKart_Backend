<?php

declare(strict_types=1);

namespace App\Controllers\Customer;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Controller handling Customer Delivery Addresses (SCR-26, SCR-27, SCR-39).
 * Protected by AuthMiddleware(['customer']).
 */
class CustomerAddressController
{
    private function resolveCustomerId(Request $request): int
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('Unauthorized. Authentication required.', 401);
        }
        if ($user['role'] !== 'customer') {
            Response::error('Forbidden. Only customers can manage delivery addresses.', 403);
        }
        return (int)$user['id'];
    }

    /**
     * List all saved addresses for authenticated customer.
     * GET /api/v1/customer/addresses
     */
    public function index(Request $request): void
    {
        $customerId = $this->resolveCustomerId($request);

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT id, label, recipient_name, phone, address_line1, address_line2,
                        city, state, postal_code, country, delivery_instructions, is_default, created_at, updated_at
                 FROM user_addresses
                 WHERE user_id = :user_id
                 ORDER BY is_default DESC, id DESC'
            );
            $stmt->execute(['user_id' => $customerId]);
            $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format boolean is_default
            $formatted = array_map(function ($addr) {
                $addr['id'] = (int)$addr['id'];
                $addr['is_default'] = (bool)(int)$addr['is_default'];
                return $addr;
            }, $addresses);

            Response::json($formatted, 200, 'Addresses retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to retrieve addresses: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Add a new delivery address.
     * POST /api/v1/customer/addresses
     * Body: { label?, recipient_name, phone, address_line1, address_line2?, city, state, postal_code, country?, delivery_instructions?, is_default? }
     */
    public function store(Request $request): void
    {
        $customerId = $this->resolveCustomerId($request);

        $label = trim((string)$request->get('label', 'Home')) ?: 'Home';
        $recipientName = trim((string)$request->get('recipient_name', ''));
        $phone = trim((string)$request->get('phone', ''));
        $addressLine1 = trim((string)$request->get('address_line1', ''));
        $addressLine2 = trim((string)$request->get('address_line2', '')) ?: null;
        $city = trim((string)$request->get('city', ''));
        $state = trim((string)$request->get('state', ''));
        $postalCode = trim((string)$request->get('postal_code', ''));
        $country = trim((string)$request->get('country', 'India')) ?: 'India';
        $instructions = trim((string)$request->get('delivery_instructions', '')) ?: null;
        $isDefault = (bool)$request->get('is_default', false);

        $errors = [];
        if ($recipientName === '') $errors['recipient_name'] = 'Recipient full name is required.';
        if ($phone === '') $errors['phone'] = 'Contact phone number is required.';
        if ($addressLine1 === '') $errors['address_line1'] = 'Address line 1 (Street/Building) is required.';
        if ($city === '') $errors['city'] = 'City is required.';
        if ($state === '') $errors['state'] = 'State / Division is required.';
        if ($postalCode === '') $errors['postal_code'] = 'Postal code is required.';

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $pdo = Database::getConnection();
            $pdo->beginTransaction();

            // If marked as default or first address, clear existing defaults
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM user_addresses WHERE user_id = :user_id');
            $countStmt->execute(['user_id' => $customerId]);
            $existingCount = (int)$countStmt->fetchColumn();

            if ($existingCount === 0) {
                $isDefault = true;
            } elseif ($isDefault) {
                $pdo->prepare('UPDATE user_addresses SET is_default = 0 WHERE user_id = :user_id')->execute(['user_id' => $customerId]);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO user_addresses
                    (user_id, label, recipient_name, phone, address_line1, address_line2, city, state, postal_code, country, delivery_instructions, is_default)
                 VALUES
                    (:user_id, :label, :recipient_name, :phone, :address_line1, :address_line2, :city, :state, :postal_code, :country, :instructions, :is_default)'
            );
            $stmt->execute([
                'user_id'       => $customerId,
                'label'         => $label,
                'recipient_name'=> $recipientName,
                'phone'         => $phone,
                'address_line1' => $addressLine1,
                'address_line2' => $addressLine2,
                'city'          => $city,
                'state'         => $state,
                'postal_code'   => $postalCode,
                'country'       => $country,
                'instructions'  => $instructions,
                'is_default'    => $isDefault ? 1 : 0,
            ]);

            $newId = (int)$pdo->lastInsertId();
            $pdo->commit();

            $fetchStmt = $pdo->prepare('SELECT * FROM user_addresses WHERE id = :id LIMIT 1');
            $fetchStmt->execute(['id' => $newId]);
            $address = $fetchStmt->fetch(PDO::FETCH_ASSOC);
            $address['id'] = (int)$address['id'];
            $address['is_default'] = (bool)(int)$address['is_default'];

            Response::json($address, 201, 'Address added successfully');
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Failed to create address: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update an existing delivery address.
     * PUT or PATCH /api/v1/customer/addresses/{id}
     */
    public function update(Request $request, array $params): void
    {
        $customerId = $this->resolveCustomerId($request);
        $addressId = (int)($params['id'] ?? 0);

        if ($addressId <= 0) {
            Response::error('Invalid address ID.', 400);
        }

        try {
            $pdo = Database::getConnection();

            $checkStmt = $pdo->prepare('SELECT * FROM user_addresses WHERE id = :id AND user_id = :user_id LIMIT 1');
            $checkStmt->execute(['id' => $addressId, 'user_id' => $customerId]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                Response::error('Address not found.', 404);
            }

            $label = $request->get('label') !== null ? trim((string)$request->get('label')) : $existing['label'];
            $recipientName = $request->get('recipient_name') !== null ? trim((string)$request->get('recipient_name')) : $existing['recipient_name'];
            $phone = $request->get('phone') !== null ? trim((string)$request->get('phone')) : $existing['phone'];
            $addressLine1 = $request->get('address_line1') !== null ? trim((string)$request->get('address_line1')) : $existing['address_line1'];
            $addressLine2 = $request->get('address_line2') !== null ? trim((string)$request->get('address_line2')) : $existing['address_line2'];
            $city = $request->get('city') !== null ? trim((string)$request->get('city')) : $existing['city'];
            $state = $request->get('state') !== null ? trim((string)$request->get('state')) : $existing['state'];
            $postalCode = $request->get('postal_code') !== null ? trim((string)$request->get('postal_code')) : $existing['postal_code'];
            $country = $request->get('country') !== null ? trim((string)$request->get('country')) : $existing['country'];
            $instructions = $request->get('delivery_instructions') !== null ? trim((string)$request->get('delivery_instructions')) : $existing['delivery_instructions'];
            $isDefault = $request->get('is_default') !== null ? (bool)$request->get('is_default') : (bool)(int)$existing['is_default'];

            $pdo->beginTransaction();

            if ($isDefault) {
                $pdo->prepare('UPDATE user_addresses SET is_default = 0 WHERE user_id = :user_id')->execute(['user_id' => $customerId]);
            }

            $updateStmt = $pdo->prepare(
                'UPDATE user_addresses SET
                    label = :label,
                    recipient_name = :recipient_name,
                    phone = :phone,
                    address_line1 = :address_line1,
                    address_line2 = :address_line2,
                    city = :city,
                    state = :state,
                    postal_code = :postal_code,
                    country = :country,
                    delivery_instructions = :instructions,
                    is_default = :is_default,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND user_id = :user_id'
            );
            $updateStmt->execute([
                'label'         => $label,
                'recipient_name'=> $recipientName,
                'phone'         => $phone,
                'address_line1' => $addressLine1,
                'address_line2' => $addressLine2,
                'city'          => $city,
                'state'         => $state,
                'postal_code'   => $postalCode,
                'country'       => $country,
                'instructions'  => $instructions,
                'is_default'    => $isDefault ? 1 : 0,
                'id'            => $addressId,
                'user_id'       => $customerId,
            ]);

            $pdo->commit();

            $fetchStmt = $pdo->prepare('SELECT * FROM user_addresses WHERE id = :id LIMIT 1');
            $fetchStmt->execute(['id' => $addressId]);
            $updated = $fetchStmt->fetch(PDO::FETCH_ASSOC);
            $updated['id'] = (int)$updated['id'];
            $updated['is_default'] = (bool)(int)$updated['is_default'];

            Response::json($updated, 200, 'Address updated successfully');
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Failed to update address: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a delivery address.
     * DELETE /api/v1/customer/addresses/{id}
     */
    public function destroy(Request $request, array $params): void
    {
        $customerId = $this->resolveCustomerId($request);
        $addressId = (int)($params['id'] ?? 0);

        if ($addressId <= 0) {
            Response::error('Invalid address ID.', 400);
        }

        try {
            $pdo = Database::getConnection();
            $delStmt = $pdo->prepare('DELETE FROM user_addresses WHERE id = :id AND user_id = :user_id');
            $delStmt->execute(['id' => $addressId, 'user_id' => $customerId]);

            if ($delStmt->rowCount() === 0) {
                Response::error('Address not found.', 404);
            }

            Response::json(['deleted_id' => $addressId], 200, 'Address deleted successfully');
        } catch (Throwable $e) {
            Response::error('Failed to delete address: ' . $e->getMessage(), 500);
        }
    }
}
