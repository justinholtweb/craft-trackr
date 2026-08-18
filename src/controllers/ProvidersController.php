<?php

namespace justinholtweb\trackr\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\trackr\models\Provider;
use justinholtweb\trackr\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Carriers in the control panel: what is offered in the picker, and what a merchant added or
 * renamed.
 */
class ProvidersController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('trackr-manageProviders');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $search = trim((string)Craft::$app->getRequest()->getParam('search', ''));

        $providers = $plugin->getProviders()->getAllProviders();

        if ($search !== '') {
            $key = Provider::normalizeKey($search);
            $providers = array_filter(
                $providers,
                static fn(Provider $provider) => str_contains(Provider::normalizeKey($provider->name), $key)
                    || str_contains(Provider::normalizeKey($provider->handle), $key)
            );
        }

        return $this->renderTemplate('trackr/providers/_index', [
            'providers' => $providers,
            'search' => $search,
            'isPro' => $plugin->isPro(),
        ]);
    }

    public function actionEdit(string $handle): Response
    {
        $plugin = Plugin::getInstance();
        $provider = $plugin->getProviders()->getProviderByHandle($handle);

        if ($provider === null && $handle !== 'new') {
            throw new NotFoundHttpException('Carrier not found');
        }

        return $this->renderTemplate('trackr/providers/_edit', [
            'provider' => $provider ?? new Provider(['isCustom' => true, 'country' => '*']),
            'isNew' => $provider === null,
            'isPro' => $plugin->isPro(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        $handle = trim((string)$request->getBodyParam('handle', ''));
        $isCustom = (bool)$request->getBodyParam('isCustom');
        $name = trim((string)$request->getBodyParam('name', ''));

        if ($isCustom && !$plugin->isPro()) {
            return $this->failure(Craft::t('trackr', 'Custom carriers are a Pro feature.'));
        }

        if ($isCustom) {
            if ($name === '') {
                return $this->failure(Craft::t('trackr', 'A carrier needs a name.'));
            }

            $handle = $handle !== '' ? $handle : Provider::handleFromName($name);

            $saved = $plugin->getProviders()->saveCustomProvider($handle, [
                'name' => $name,
                'url' => trim((string)$request->getBodyParam('url', '')),
                'country' => trim((string)$request->getBodyParam('country', '*')) ?: '*',
                'logoUrl' => trim((string)$request->getBodyParam('logoUrl', '')),
                'match' => array_values(array_filter(array_map(
                    'trim',
                    preg_split('/\r\n|\r|\n/', (string)$request->getBodyParam('match', '')) ?: []
                ))),
                'enabled' => (bool)$request->getBodyParam('enabled', true),
                'sortOrder' => (int)$request->getBodyParam('sortOrder', 0),
            ]);
        } else {
            $saved = $plugin->getProviders()->saveOverride($handle, array_filter([
                'name' => trim((string)$request->getBodyParam('name', '')),
                'url' => trim((string)$request->getBodyParam('url', '')),
                'logoUrl' => trim((string)$request->getBodyParam('logoUrl', '')),
                'enabled' => (bool)$request->getBodyParam('enabled', true),
            ], static fn($value, $key) => $key === 'enabled' || $value !== '', ARRAY_FILTER_USE_BOTH));
        }

        return $saved
            ? $this->success(Craft::t('trackr', 'Carrier saved.'))
            : $this->failure(Craft::t('trackr', 'Couldn’t save that carrier.'));
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();

        $handle = (string)Craft::$app->getRequest()->getRequiredBodyParam('handle');

        return Plugin::getInstance()->getProviders()->deleteCustomProvider($handle)
            ? $this->success(Craft::t('trackr', 'Carrier deleted.'))
            : $this->failure(Craft::t('trackr', 'Only custom carriers can be deleted. Disable a built-in one instead.'));
    }

    /**
     * Turn a built-in carrier on or off in the picker.
     */
    public function actionToggle(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $handle = (string)$request->getRequiredBodyParam('handle');
        $enabled = (bool)$request->getBodyParam('enabled', true);

        $plugin = Plugin::getInstance();
        $provider = $plugin->getProviders()->getProviderByHandle($handle);

        if ($provider === null) {
            return $this->failure(Craft::t('trackr', 'Carrier not found.'));
        }

        $saved = $provider->isCustom
            ? $plugin->getProviders()->saveCustomProvider($handle, array_merge($provider->toRegistryArray(), ['enabled' => $enabled]))
            : $plugin->getProviders()->saveOverride($handle, ['enabled' => $enabled]);

        return $saved
            ? $this->success($enabled ? Craft::t('trackr', 'Carrier enabled.') : Craft::t('trackr', 'Carrier disabled.'))
            : $this->failure(Craft::t('trackr', 'Couldn’t change that carrier.'));
    }

    /**
     * Build a tracking URL from a sample number so the merchant can click it before trusting it.
     */
    public function actionTest(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $handle = (string)$request->getRequiredBodyParam('handle');
        $number = (string)$request->getBodyParam('trackingNumber', '1Z999AA10123456784');

        $provider = Plugin::getInstance()->getProviders()->getProviderByHandle($handle);

        if ($provider === null) {
            return $this->asFailure(Craft::t('trackr', 'Carrier not found.'));
        }

        $url = $provider->buildUrl($number, [
            'postal_code' => '90210',
            'country' => 'US',
            'phone' => '5551234567',
            'ship_date' => date('Y-m-d'),
        ]);

        return $this->asJson([
            'success' => $url !== null,
            'url' => $url,
            'message' => $url === null
                ? Craft::t('trackr', 'This carrier has no tracking URL, so its numbers show as plain text.')
                : $url,
        ]);
    }

    private function success(string $message): Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess($message);
        }

        Craft::$app->getSession()->setNotice($message);

        return $this->redirectToPostedUrl(null, 'trackr/providers');
    }

    private function failure(string $message): Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asFailure($message);
        }

        Craft::$app->getSession()->setError($message);

        return $this->redirectToPostedUrl(null, 'trackr/providers');
    }
}
