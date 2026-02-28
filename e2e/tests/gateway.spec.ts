import { test, expect } from '@playwright/test';

test.describe('Stripe Terminal Gateway', () => {
  test('gateway appears in WooCommerce admin payment settings', async ({ page }) => {
    // Log into WordPress admin.
    await page.goto('/wp-login.php');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'admin');
    await page.click('#wp-submit');

    // Navigate to WooCommerce payment settings.
    await page.goto('/wp-admin/admin.php?page=wc-settings&tab=checkout');

    // Verify Stripe Terminal is listed as a payment method.
    await expect(page.locator('text=Stripe Terminal')).toBeVisible();
  });

  test('gateway settings page loads', async ({ page }) => {
    await page.goto('/wp-login.php');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'admin');
    await page.click('#wp-submit');

    await page.goto(
      '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=stripe_terminal_for_woocommerce'
    );

    // Verify key settings fields are present.
    await expect(page.locator('text=Enable/Disable')).toBeVisible();
    await expect(page.locator('text=Test Mode')).toBeVisible();
    await expect(page.locator('text=Test Secret Key')).toBeVisible();
  });
});
