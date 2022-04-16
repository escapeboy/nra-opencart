<?php
declare(strict_types=1);

class ModelExtensionModuleNra extends Model
{
    public function getOrders(string $startDate, string $endDate)
    {
        $sql = "SELECT o.order_id, CONCAT(o.firstname, ' ', o.lastname) AS customer, 
        (SELECT os.name FROM " . DB_PREFIX . "order_status os WHERE os.order_status_id = o.order_status_id AND os.language_id = '" . (int)$this->config->get('config_language_id') . "') AS order_status, 
        o.order_status_id, o.payment_code,
        o.shipping_code, o.total, o.currency_code, o.currency_value, o.date_added, o.date_modified, o.invoice_prefix, o.invoice_no FROM `" . DB_PREFIX . "order` o";
        $sql .= " WHERE o.date_added BETWEEN '" . $startDate . "' AND '" . $endDate . "'";
        $sql .= " ORDER BY o.date_added DESC";

        return $this->db->query($sql)->rows;
    }

    public function getPaymentMethods()
    {
        $sql = "SELECT code FROM " . DB_PREFIX . "extension e WHERE type = 'payment'";

        return $this->db->query($sql)->rows;
    }

    public function getOrderStatuses()
    {
        $sql = "SELECT order_status_id, name FROM " . DB_PREFIX . "order_status WHERE language_id = " . (int)$this->config->get('config_language_id');

        return $this->db->query($sql)->rows;
    }

    public function getOrderItems($orderId)
    {
        $sql = "SELECT * FROM " . DB_PREFIX . "order_product WHERE order_id = " . $orderId;

        return $this->db->query($sql)->rows;
    }
}
