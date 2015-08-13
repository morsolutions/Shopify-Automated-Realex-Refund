# Shopify-Automated-Realex-Refund
------------------------------------
Automated Realex Refund for Shopify
------------------------------------

This script allows to refund specified amount straight from Shopify.

Instructions:
1. Create new Webhook event 'Refund create' (JSON format) in Shopify: Settings->Notifications->Webhooks.
2. Callback URL must be to refund.php file.
3. Update refund.php file with required details where placeholder '#' appears.
4. Setup your preferred mail sending library and update refund.php file where required.
5. Contact Realex to whitelist the IP address of server where your refund.php file is placed.


