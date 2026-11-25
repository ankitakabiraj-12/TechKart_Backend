<?php
$dir = 'c:/Users/user/OneDrive/Desktop/techkart_buddy/lib';
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
$changed = 0;

foreach ($files as $f) {
    if ($f->isFile() && $f->getExtension() === 'dart') {
        $path = $f->getRealPath();
        $content = file_get_contents($path);
        $orig = $content;

        $content = str_replace('৳', '₹', $content);
        $content = str_replace('BDT', 'INR', $content);
        $content = str_replace('Bangladesh Cyber Security Act 2023', 'Information Technology Act 2000', $content);
        $content = str_replace('Bangladesh Cyber Security Act', 'Information Technology Act', $content);
        $content = str_replace('Bangladesh', 'India', $content);
        $content = str_replace('Dhaka Central Hub (DHK-04)', 'Mumbai Central Hub (BOM-01)', $content);
        $content = str_replace('Dhaka', 'Mumbai', $content);
        $content = str_replace('Chittagong', 'Bengaluru', $content);
        $content = str_replace('Sylhet', 'Delhi', $content);
        $content = str_replace('bKash', 'UPI / Razorpay', $content);
        $content = str_replace('bkash', 'upi.razorpay', $content);
        $content = str_replace('Nagad', 'Paytm', $content);
        $content = str_replace('SSLCommerz', 'Razorpay', $content);
        $content = str_replace('BEFTN', 'NEFT / RTGS', $content);
        $content = str_replace('+880', '+91', $content);
        $content = str_replace('GST-BD-', 'GST-IN-', $content);
        $content = str_replace('Apex Gear BD', 'Apex Gear India', $content);
        $content = str_replace('GadgetPlanet BD', 'GadgetPlanet India', $content);

        if ($content !== $orig) {
            file_put_contents($path, $content);
            $changed++;
            echo "Updated: " . basename($path) . "\n";
        }
    }
}
echo "Total Flutter files updated: $changed\n";

