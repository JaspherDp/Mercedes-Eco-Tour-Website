<?php
require_once __DIR__ . '/input_validation.php';

function bookingTouristManifestRows(array $input): array
{
    $manifestKeys = ['full_name', 'gender', 'age', 'phone_number', 'country', 'region', 'province', 'city', 'barangay', 'postal_code', 'street'];
    foreach ($manifestKeys as $key) {
        if (!is_array($input[$key] ?? null)) throw new InvalidArgumentException('Tourist details must be submitted as aligned lists.');
    }
    $names = $input['full_name'];
    if (count($names) > 100) throw new InvalidArgumentException('A manifest may contain at most 100 tourists.');
    foreach ($manifestKeys as $key) {
        if (count($input[$key]) !== count($names)) throw new InvalidArgumentException('Tourist detail lists must have matching row counts.');
    }
    $rows = [];
    foreach ($names as $index => $rawName) {
        $name = ItourValidationText($rawName, 'Tourist name', 180);
        if ($name === '') {
            continue;
        }

        $gender = strtolower(trim((string)($input['gender'][$index] ?? '')));
        $ageValue = filter_var($input['age'][$index] ?? null, FILTER_VALIDATE_INT);
        $phone = ItourValidationText($input['phone_number'][$index] ?? '', 'Tourist phone', 50);
        $country = ItourValidationText($input['country'][$index] ?? '', 'Country', 100);
        $region = ItourValidationText($input['region'][$index] ?? '', 'Region', 120);
        $province = ItourValidationText($input['province'][$index] ?? '', 'Province', 120);
        $city = ItourValidationText($input['city'][$index] ?? '', 'City', 120);
        $barangay = ItourValidationText($input['barangay'][$index] ?? '', 'Barangay', 120);
        $postalCode = ItourValidationText($input['postal_code'][$index] ?? '', 'Postal code', 20);
        $street = ItourValidationText($input['street'][$index] ?? '', 'Street', 180);

        if (!in_array($gender, ['male', 'female'], true)) {
            throw new InvalidArgumentException("Select a valid gender for tourist " . ($index + 1) . '.');
        }
        if ($ageValue === false || $ageValue < 0 || $ageValue > 120) {
            throw new InvalidArgumentException("Enter a valid age from 0 to 120 for tourist " . ($index + 1) . '.');
        }
        if ($phone === '') {
            throw new InvalidArgumentException("Enter a contact number for tourist " . ($index + 1) . '.');
        }
        if (!preg_match('/^[0-9+().\-\s]{7,50}$/D', $phone)) {
            throw new InvalidArgumentException("Enter a valid contact number for tourist " . ($index + 1) . '.');
        }
        if ($country === '' || $region === '' || $province === '' || $city === '' || $barangay === '' || $postalCode === '' || $street === '') {
            throw new InvalidArgumentException("Complete the address for tourist " . ($index + 1) . '.');
        }

        $address = implode(', ', [$street, $barangay, $city, $province, $region, $postalCode, $country]);
        $rows[] = [
            'full_name' => $name,
            'gender' => $gender,
            'age' => $ageValue,
            'address' => ItourValidationText($address, 'Tourist address', 500, true),
            'country' => $country,
            'region' => $region,
            'province' => $province,
            'city' => $city,
            'barangay' => $barangay,
            'postal_code' => $postalCode,
            'street' => $street,
            'residence' => strtolower($country) === 'philippines' ? 'philippines' : 'foreign',
            'phone_number' => $phone,
        ];
    }

    if (!$rows) {
        throw new InvalidArgumentException('Add at least one tourist.');
    }
    return $rows;
}

