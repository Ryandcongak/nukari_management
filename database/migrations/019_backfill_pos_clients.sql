UPDATE product_cashier_sales sales
JOIN clients client
  ON (sales.customer_email IS NOT NULL AND sales.customer_email <> '' AND LOWER(sales.customer_email) = LOWER(client.email))
  OR (sales.customer_phone IS NOT NULL AND sales.customer_phone <> '' AND sales.customer_phone = client.phone)
SET sales.client_id = client.id
WHERE sales.client_id IS NULL;

UPDATE product_custom_orders orders
JOIN clients client
  ON (orders.customer_email IS NOT NULL AND orders.customer_email <> '' AND LOWER(orders.customer_email) = LOWER(client.email))
  OR (orders.customer_phone IS NOT NULL AND orders.customer_phone <> '' AND orders.customer_phone = client.phone)
SET orders.client_id = client.id
WHERE orders.client_id IS NULL;

UPDATE product_sales sales
JOIN clients client ON sales.customer_name = client.client_name
SET sales.client_id = client.id
WHERE sales.client_id IS NULL
  AND sales.customer_name IS NOT NULL
  AND sales.customer_name <> '';
