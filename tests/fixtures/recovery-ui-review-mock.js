async (page) => {
  await page.route("**/api/v1/admin/recovery-status.php", async (route) => {
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        data: {
          restore_target: {
            configured: true,
            ready: true,
            message: "Disposable empty UI-review target.",
          },
        },
      }),
    });
  });
  await page.route("**/api/v1/admin/restore-inspections.php", async (route) => {
    await page.waitForTimeout(450);
    await route.fulfill({
      status: 201,
      contentType: "application/json",
      body: JSON.stringify({
        data: {
          inspection_id: "22222222-2222-4222-8222-222222222222",
          expires_at: "2099-01-01T00:00:00Z",
          metadata: {
            envelope_sha256: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
            durable_row_counts: { messages: 1 },
            backup_policy: {
              durable: { count: 28, tables: [] },
              reset: { count: 17, tables: [] },
              excluded: { count: 3, tables: [] },
            },
          },
        },
      }),
    });
  });
  return "Recovery UI review routes installed. Reload Backup / Restore before testing.";
}
