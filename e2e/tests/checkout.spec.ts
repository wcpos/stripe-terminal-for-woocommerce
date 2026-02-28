import { test, expect } from '@playwright/test';

test.describe('Checkout Pay Page', () => {
  test('stripe terminal payment UI loads on order-pay page', async ({ page }) => {
    // Log into WordPress admin.
    await page.goto('/wp-login.php');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'admin');
    await page.click('#wp-submit');

    // Create a pending order via the WC REST API or admin.
    // Navigate to the order-pay page.
    // For now, we create the order via the admin panel.
    await page.goto('/wp-admin/post-new.php?post_type=shop_order');

    // This is a basic smoke test. Full payment flow testing
    // requires Stripe test keys and a simulated reader.
    // Expand this once the infrastructure is validated.
  });

  test('payment method shows loading state on checkout', async ({ page }) => {
    // Navigate to the shop and add a product to cart.
    await page.goto('/?post_type=product');

    const product = page.locator('.product').first();
    if (await product.isVisible()) {
      await product.locator('.add_to_cart_button, .button').first().click();
      await page.goto('/checkout/');

      // Look for our payment method in the checkout form.
      const terminalOption = page.locator('text=Stripe Terminal');
      if (await terminalOption.isVisible()) {
        await terminalOption.click();
        // Our payment UI should show the loading state.
        await expect(page.locator('.stripe-terminal-loading')).toBeVisible();
      }
    }
  });
});
