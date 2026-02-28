import { test, expect } from '@playwright/test';

test.describe('Checkout Pay Page', () => {
  test.skip('stripe terminal payment UI loads on order-pay page', async ({ page }) => {
    // TODO: Add assertions once infrastructure supports order creation
    // and Stripe test keys are configured for terminal simulation.
    await page.goto('/wp-login.php');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'admin');
    await page.click('#wp-submit');

    await page.goto('/wp-admin/post-new.php?post_type=shop_order');
  });

  test('payment method shows loading state on checkout', async ({ page }) => {
    // Navigate to the shop and add a product to cart.
    await page.goto('/?post_type=product');

    const product = page.locator('.product').first();
    await expect(product).toBeVisible({ timeout: 10000 });
    await product.locator('.add_to_cart_button, .button').first().click();
    await page.goto('/checkout/');

    // Look for our payment method in the checkout form.
    const terminalOption = page.locator('text=Stripe Terminal');
    await expect(terminalOption).toBeVisible({ timeout: 10000 });
    await terminalOption.click();

    // Our payment UI should show the loading state.
    await expect(page.locator('.stripe-terminal-loading')).toBeVisible();
  });
});
