import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';

const runId = Date.now().toString();
const clientName = `QA Müştəri ${runId}`;
const leadName = `QA Lid ${runId}`;
const supplierName = `QA Təchizatçı ${runId}`;
const employeeName = `QA Dizayner ${runId}`;
const employeeEmail = `qa.designer.${runId}@test.az`;
const studioName = `QA Studio ${runId}`;

async function login(page, email, password = 'secret123') {
    await page.goto('/idaresistem229/login');
    await page.locator('input[type="email"]').fill(email);
    await page.locator('input[type="password"]').fill(password);
    await page.getByRole('button', { name: 'Giriş Et', exact: true }).click();
    await page.waitForURL('**/idaresistem229');
}

async function choose(page, label, option) {
    await page.getByRole('combobox', { name: new RegExp(`^${label}`) }).click();
    await page.getByRole('option', { name: option, exact: true }).click();
}

test.describe('real CRM workflows', () => {
    test('scenario 1: platform admin creates and edits a studio', async ({ page }) => {
        await login(page, 'qa.superadmin@archicrm.test', 'QaSecure123!');
        await page.goto('/idaresistem229/tenants/create');
        await page.locator('#form\\.name').fill(studioName);
        await page.locator('#form\\.slug').fill(`qa-studio-${runId}`);
        await page.locator('#form\\.owner_name').fill(`QA Studio Owner ${runId}`);
        await page.locator('#form\\.owner_email').fill(`qa.studio.owner.${runId}@test.az`);
        await page.locator('#form\\.owner_password').fill('QaStudioOwner123!');
        await page.getByRole('button', { name: 'Yarat', exact: true }).click();

        await expect(page.getByText('Yaradıldı', { exact: true })).toBeVisible({ timeout: 20_000 });
        await page.locator('#form\\.name').fill(`${studioName} Redaktə`);
        await page.getByRole('button', { name: 'Dəyişiklikləri yadda saxla', exact: true }).click();
        await expect(page.getByText('Yadda saxlanıldı', { exact: true })).toBeVisible();
    });

    test('scenario 2: studio owner creates and edits a lead', async ({ page }) => {
        await login(page, 'owner@alfa.test');
        await page.goto('/idaresistem229/leads/create');
        await page.locator('#form\\.first_name').fill(leadName);
        await page.locator('#form\\.phone').fill('+994501230001');
        await page.locator('#form\\.email').fill(`lead.${runId}@test.az`);
        await page.getByRole('button', { name: 'Yarat', exact: true }).click();

        await expect(page.getByText('Yaradıldı', { exact: true })).toBeVisible();
        await page.locator('#form\\.company').fill('QA Architecture MMC');
        await page.getByRole('button', { name: 'Dəyişiklikləri yadda saxla', exact: true }).click();
        await expect(page.getByText('Yadda saxlanıldı', { exact: true })).toBeVisible();
    });

    test('scenario 3: owner accepts a client and updates contact details', async ({ page }) => {
        await login(page, 'owner@alfa.test');
        await page.goto('/idaresistem229/clients/create');
        await page.locator('#form\\.name').fill(clientName);
        await page.locator('#form\\.company').fill('QA Design Group');
        await page.locator('#form\\.phone').fill('+994501230002');
        await page.locator('#form\\.email').fill(`client.${runId}@test.az`);
        await page.locator('#form\\.notes').fill('Real QA qəbul ssenarisi');
        await page.getByRole('button', { name: 'Yarat', exact: true }).click();

        await expect(page.getByText('Yaradıldı', { exact: true })).toBeVisible();
        await page.locator('#form\\.telegram').fill(`qa_${runId}`);
        await page.getByRole('button', { name: 'Dəyişiklikləri yadda saxla', exact: true }).click();
        await expect(page.getByText('Yadda saxlanıldı', { exact: true })).toBeVisible();
    });

    test('scenario 4: owner creates and edits an employee', async ({ page }) => {
        await login(page, 'owner@alfa.test');
        await page.goto('/idaresistem229/users/create');
        await page.locator('#form\\.name').fill(employeeName);
        await page.locator('#form\\.email').fill(employeeEmail);
        await page.locator('#form\\.password').fill('QaEmployee123!');
        await choose(page, 'Baza rolu', 'Baş dizayner / Memar');
        await page.getByRole('button', { name: 'Yarat', exact: true }).click();

        await expect(page.getByText('Yaradıldı', { exact: true })).toBeVisible();
        await page.locator('#form\\.phone').fill('+994501230003');
        await page.getByRole('button', { name: 'Dəyişiklikləri yadda saxla', exact: true }).click();
        await expect(page.getByText('Yadda saxlanıldı', { exact: true })).toBeVisible();
    });

    test('scenario 5: owner creates and edits a supplier', async ({ page }) => {
        await login(page, 'owner@alfa.test');
        await page.goto('/idaresistem229/suppliers/create');
        await page.locator('#form\\.name').fill(supplierName);
        await page.locator('#form\\.category').fill('Mebel');
        await page.locator('#form\\.phone').fill('+994501230004');
        await page.getByRole('button', { name: 'Yarat', exact: true }).click();

        await expect(page.getByText('Yaradıldı', { exact: true })).toBeVisible();
        await page.locator('#form\\.payment_terms').fill('50% avans, 50% təhvil');
        await page.getByRole('button', { name: 'Dəyişiklikləri yadda saxla', exact: true }).click();
        await expect(page.getByText('Yadda saxlanıldı', { exact: true })).toBeVisible();
    });

    test('scenario 6: designer sees only own tasks and completes one', async ({ page }) => {
        execFileSync('C:/Users/User/.config/herd/bin/php84/php.exe', [
            'artisan',
            'tinker',
            "--execute=App\\Models\\Task::withoutGlobalScope('tenant')->where('id',7)->update(['status'=>'todo','completed_at'=>null]);",
        ]);
        await login(page, 'designer@alfa.test');
        await page.goto('/idaresistem229/tasks');

        await expect(page.getByText('Smetanı yoxla', { exact: true })).toHaveCount(0);
        const taskRow = page.getByRole('row').filter({ has: page.getByRole('button', { name: 'Hazırdır' }) }).first();
        await taskRow.getByRole('button', { name: 'Hazırdır' }).click();
        await expect(taskRow).toHaveCount(0);
    });

    test('scenario 7: role boundaries block direct module access', async ({ page }) => {
        await login(page, 'designer@alfa.test');

        for (const path of ['/idaresistem229/invoices', '/idaresistem229/expenses', '/idaresistem229/tenants', '/idaresistem229/translations']) {
            const response = await page.goto(path);
            expect(response?.status()).toBe(403);
        }
    });

    test('scenario 8: client uses magic login, opens brief, and sends chat message', async ({ page }) => {
        const code = `$u=App\\Models\\ClientUser::withoutGlobalScope('tenant')->where('email','client@alfa.test')->firstOrFail(); $t='qa-${runId}'; $u->forceFill(['magic_token'=>hash('sha256',$t)])->save(); echo URL::temporarySignedRoute('portal.magic-login',now()->addHour(),['clientUser'=>$u->id,'t'=>$t]);`;
        const magicUrl = execFileSync('C:/Users/User/.config/herd/bin/php84/php.exe', ['artisan', 'tinker', `--execute=${code}`], { encoding: 'utf8' }).trim();

        await page.goto(magicUrl);
        await expect(page).toHaveURL(/\/portal(?:\/projects\/7)?$/);
        await page.goto('/portal/projects/7/brief');
        await expect(page.locator('body')).toContainText('Brif');
        await page.goto('/portal/projects/7/chat');
        await page.locator('#chatInput').fill(`QA müştəri mesajı ${runId}`);
        await page.getByRole('button', { name: 'Göndər', exact: true }).click();
        await expect(page.locator('#chatThread')).toContainText(`QA müştəri mesajı ${runId}`);
    });

    test('scenario 9: owner can render every permitted module and CRUD form', async ({ page }) => {
        test.setTimeout(120_000);
        const browserErrors = [];
        page.on('pageerror', error => browserErrors.push(error.message));
        await login(page, 'owner@alfa.test');

        const listPaths = [
            '/idaresistem229/invoices', '/idaresistem229/expenses', '/idaresistem229/profitability',
            '/idaresistem229/leads', '/idaresistem229/clients', '/idaresistem229/projects',
            '/idaresistem229/calendar', '/idaresistem229/tasks', '/idaresistem229/chat-center',
            '/idaresistem229/approvals', '/idaresistem229/meetings', '/idaresistem229/suppliers',
            '/idaresistem229/purchase-orders', '/idaresistem229/users', '/idaresistem229/roles',
            '/idaresistem229/automation-rules', '/idaresistem229/time-entries',
        ];
        const createPaths = [
            '/idaresistem229/invoices/create', '/idaresistem229/expenses/create',
            '/idaresistem229/leads/create', '/idaresistem229/clients/create',
            '/idaresistem229/projects/create', '/idaresistem229/tasks/create',
            '/idaresistem229/meetings/create', '/idaresistem229/suppliers/create',
            '/idaresistem229/purchase-orders/create', '/idaresistem229/users/create',
            '/idaresistem229/roles/create',
            '/idaresistem229/time-entries/create',
        ];

        for (const path of [...listPaths, ...createPaths]) {
            const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
            expect(response?.status(), path).toBe(200);
            await expect(page.locator('main'), path).toBeVisible();

            if (path === '/idaresistem229/profitability') {
                await expect(page.getByText('Yığılmış gəlir', { exact: true })).toHaveCount(1);
                await expect(page.getByText('Layihələr üzrə rentabellik', { exact: true })).toHaveCount(1);
            }
        }

        expect(browserErrors).toEqual([]);
    });
});
