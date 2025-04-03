# Complete Step-by-Step Guide to Setting Up M-Pesa Daraja and WooCommerce Plugin

Here's a comprehensive guide to implementing M-Pesa payments on your WooCommerce store:

## Part 1: Setting Up M-Pesa Daraja API

### Step 1: Register for Daraja API Access
1. Go to the [Safaricom Developer Portal](https://developer.safaricom.co.ke/)
2. Create an account or log in if you already have one
3. Navigate to "My Applications" and create a new application
4. Select "M-Pesa Express (STK Push)" as your API type

### Step 2: Get Your API Credentials
1. Note down your:
   - Consumer Key
   - Consumer Secret
   - Passkey (found under Lipa Na M-Pesa Online)
   - Shortcode (Paybill or Till number)

### Step 3: Configure Daraja Environment
1. For **Sandbox Testing**:
   - Use test credentials provided in the developer portal
   - Test numbers: 254708374149, 254703459309, etc.

2. For **Production**:
   - Submit your business documents for approval
   - Once approved, activate your production environment
   - Use your live Paybill/Till number and credentials

## Part 2: Installing and Configuring the Plugin

### Step 1: Install the Plugin
1. In your WordPress admin, go to **Plugins > Add New**
2. Click "Upload Plugin" and select your `woo-mpesa-gateway.zip` file
3. Click "Install Now" then "Activate Plugin"

### Step 2: Configure Plugin Settings
1. Go to **WooCommerce > Settings > Payments**
2. Find "M-Pesa" in the payment methods list and click "Set up"
3. Configure the following settings:

   - **Enable/Disable**: Check to enable
   - **Title**: "M-Pesa" (or your preferred display name)
   - **Description**: "Pay via M-Pesa STK Push"
   - **Instructions**: Add payment instructions for customers
   - **Consumer Key**: Your Daraja API consumer key
   - **Consumer Secret**: Your Daraja API consumer secret
   - **Shortcode**: Your Paybill or Till number
   - **Passkey**: Your Lipa Na M-Pesa Online passkey
   - **Sandbox Mode**: Enable for testing, disable for production

4. Click "Save changes"

### Step 3: Set Up Callback URL
1. In your Daraja API settings on the Safaricom portal:
   - Set your callback URL to: `https://yourdomain.com/wc-api/mpesa_callback`
   - (Replace "yourdomain.com" with your actual domain)

2. In WordPress, go to **Settings > Permalinks**
   - Click "Save Changes" to flush rewrite rules (no need to change settings)

## Part 3: Testing Your Setup

### Sandbox Testing
1. Place a test order on your site
2. Select M-Pesa as payment method
3. Use test phone numbers (254708374149, etc.)
4. Enter test PIN: 174379
5. Verify payment is processed correctly

### Testing Callbacks
1. Use a tool like Postman to simulate callbacks
2. Send test payload to your callback URL
3. Check WooCommerce orders to verify status changes

## Part 4: Going Live

1. Once testing is successful:
   - Disable Sandbox mode in plugin settings
   - Enter your production credentials
   - Submit your production application if not already done

2. Verify with real transactions:
   - Start with small test amounts
   - Confirm payments reflect in your M-Pesa account

## Troubleshooting Tips

1. **API Errors**:
   - Verify all credentials are correct
   - Check Daraja API status page for outages

2. **Callback Issues**:
   - Ensure your server can receive POST requests
   - Check WooCommerce logs for errors

3. **Common Problems**:
   - "Invalid consumer key/secret" → Regenerate credentials
   - "Request timeout" → Check your server's internet connection
   - "Callback not received" → Verify URL is correct and accessible

## Maintenance

1. Regularly:
   - Check for plugin updates
   - Monitor transaction logs
   - Renew API tokens before expiry

2. Security:
   - Keep API credentials secure
   - Implement SSL certificate (HTTPS)
   - Regularly update WordPress and WooCommerce

This complete setup will give you a fully functional M-Pesa payment gateway integrated with WooCommerce. The plugin handles the STK Push process automatically, providing a seamless payment experience for your customers.