<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\KnowledgeResource;
use App\Models\Knowledge;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class KnowledgeController extends Controller
{
    private UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function fetch(Request $request)
    {
        $request->validate([
            'id' => 'nullable|sometimes|integer|min:1',
            'language' => 'nullable|sometimes|string|max:10',
            'keyword' => 'nullable|sometimes|string|max:255',
        ]);

        return $request->input('id')
            ? $this->fetchSingle($request)
            : $this->fetchList($request);
    }

    public function getCategory(Request $request)
    {
        $request->validate([
            'language' => 'nullable|sometimes|string|max:10',
        ]);

        $categories = $this->buildKnowledgeQuery(['category'])
            ->when($request->filled('language'), function ($query) use ($request) {
                $query->where('language', $request->input('language'));
            })
            ->orderBy('sort', 'ASC')
            ->get()
            ->pluck('category')
            ->filter()
            ->unique()
            ->values();

        return $this->success($categories);
    }

    private function fetchSingle(Request $request)
    {
        $knowledge = $this->buildKnowledgeQuery()
            ->where('id', $request->input('id'))
            ->first();

        if (!$knowledge) {
            return $this->fail([500, __('Article does not exist')]);
        }

        $knowledge = $knowledge->toArray();
        $knowledge = $this->processKnowledgeContent($knowledge, $this->buildRenderContext($request->user()));

        return $this->success(KnowledgeResource::make($knowledge));
    }

    private function fetchList(Request $request)
    {
        $builder = $this->buildKnowledgeQuery(['id', 'category', 'title', 'updated_at', 'body'])
            ->where('language', $request->input('language'))
            ->orderBy('sort', 'ASC');

        $keyword = $request->input('keyword');
        if ($keyword) {
            $builder = $builder->where(function ($query) use ($keyword) {
                $query->where('title', 'LIKE', "%{$keyword}%")
                    ->orWhere('body', 'LIKE', "%{$keyword}%");
            });
        }

        $renderContext = $this->buildRenderContext($request->user());
        $knowledges = $builder->get()
            ->map(function ($knowledge) use ($renderContext) {
                $knowledge = $knowledge->toArray();
                $knowledge = $this->processKnowledgeContent($knowledge, $renderContext);
                return KnowledgeResource::make($knowledge);
            })
            ->groupBy('category');

        return $this->success($knowledges);
    }

    private function buildKnowledgeQuery(array $select = ['*'])
    {
        return Knowledge::select($select)->where('show', 1);
    }

    /**
     * @return array{has_access: bool, subscribe_url: string}
     */
    private function buildRenderContext(User $user): array
    {
        return [
            'has_access' => $this->userService->isAvailable($user),
            'subscribe_url' => Helper::getSubscribeUrl($user['token']),
        ];
    }

    /**
     * @param array{has_access: bool, subscribe_url: string} $renderContext
     */
    private function processKnowledgeContent(array $knowledge, array $renderContext): array
    {
        if (!isset($knowledge['body'])) {
            return $knowledge;
        }

        if (!$renderContext['has_access']) {
            $this->formatAccessData($knowledge['body']);
        }
        $knowledge['body'] = $this->replacePlaceholders($knowledge['body'], $renderContext['subscribe_url']);

        return $knowledge;
    }

    private function formatAccessData(&$body): void
    {
        $rules = [
            [
                'type' => 'regex',
                'pattern' => '/<!--access start-->(.*?)<!--access end-->/s',
                'replacement' => '<div class="v2board-no-access">' . __('You must have a valid subscription to view content in this area') . '</div>'
            ]
        ];

        $this->applyReplacementRules($body, $rules);
    }

    private function replacePlaceholders(string $body, string $subscribeUrl): string
    {
        $rules = [
            [
                'type' => 'string',
                'search' => '{{siteName}}',
                'replacement' => admin_setting('app_name', 'XBoard')
            ],
            [
                'type' => 'string',
                'search' => '{{subscribeUrl}}',
                'replacement' => $subscribeUrl
            ],
            [
                'type' => 'string',
                'search' => '{{urlEncodeSubscribeUrl}}',
                'replacement' => urlencode($subscribeUrl)
            ],
            [
                'type' => 'string',
                'search' => '{{safeBase64SubscribeUrl}}',
                'replacement' => str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($subscribeUrl))
            ]
        ];

        $this->applyReplacementRules($body, $rules);
        return $body;
    }

    private function applyReplacementRules(string &$body, array $rules): void
    {
        foreach ($rules as $rule) {
            if ($rule['type'] === 'regex') {
                $body = preg_replace($rule['pattern'], $rule['replacement'], $body);
            } else {
                $body = str_replace($rule['search'], $rule['replacement'], $body);
            }
        }
    }
}
