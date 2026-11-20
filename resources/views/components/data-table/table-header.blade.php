<!-- Users Headers -->
<tr x-show="resource === 'users'" data-resource="users">
    <th scope="col" class="cursor-pointer" @click="toggleSort('id'); currentPage = 1; updateUrl()">
        ID <i class="fa-solid" :class="sortBy === 'id' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('first_name'); currentPage = 1; updateUrl()">
        {{ __('Name') }} <i class="fa-solid" :class="sortBy === 'first_name' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('email'); currentPage = 1; updateUrl()">
        {{ __('Email') }} <i class="fa-solid" :class="sortBy === 'email' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('role_name'); currentPage = 1; updateUrl()">
        {{ __('Role') }} <i class="fa-solid" :class="sortBy === 'role_name' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('status'); currentPage = 1; updateUrl()">
        {{ __('Status') }} <i class="fa-solid" :class="sortBy === 'status' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="text-end">{{ __('Actions') }}</th>
</tr>

<!-- Roles Headers -->
<tr x-show="resource === 'roles'" data-resource="roles">
    <th scope="col" class="cursor-pointer" @click="toggleSort('id'); currentPage = 1; updateUrl()">
        ID <i class="fa-solid" :class="sortBy === 'id' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('name'); currentPage = 1; updateUrl()">
        {{ __('Role Name') }} <i class="fa-solid" :class="sortBy === 'name' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('is_active'); currentPage = 1; updateUrl()">
        {{ __('Status') }} <i class="fa-solid" :class="sortBy === 'is_active' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="text-end">{{ __('Actions') }}</th>
</tr>

<!-- Pages Headers -->
<tr x-show="resource === 'pages'" data-resource="pages">
    <th scope="col" class="cursor-pointer" @click="toggleSort('id'); currentPage = 1; updateUrl()">
        ID <i class="fa-solid" :class="sortBy === 'id' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('title'); currentPage = 1; updateUrl()">
        {{ __('Title') }} <i class="fa-solid" :class="sortBy === 'title' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('slug'); currentPage = 1; updateUrl()">
        Slug <i class="fa-solid" :class="sortBy === 'slug' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('visibility'); currentPage = 1; updateUrl()">
        {{ __('Visibility') }} <i class="fa-solid" :class="sortBy === 'visibility' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('created_at'); currentPage = 1; updateUrl()">
        {{ __('Created At') }} <i class="fa-solid" :class="sortBy === 'created_at' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="text-end">{{ __('Actions') }}</th>
</tr>

<!-- Categories Headers -->
<tr x-show="resource === 'categories'" data-resource="categories">
    <th scope="col" class="cursor-pointer" @click="toggleSort('id'); currentPage = 1; updateUrl()">
        ID <i class="fa-solid" :class="sortBy === 'id' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('name'); currentPage = 1; updateUrl()">
        {{ __('Name') }} <i class="fa-solid" :class="sortBy === 'name' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('language'); currentPage = 1; updateUrl()">
        {{ __('Language') }} <i class="fa-solid" :class="sortBy === 'language' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('is_active'); currentPage = 1; updateUrl()">
        {{ __('Status') }} <i class="fa-solid" :class="sortBy === 'is_active' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('created_at'); currentPage = 1; updateUrl()">
        {{ __('Created At') }} <i class="fa-solid" :class="sortBy === 'created_at' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="text-end">{{ __('Actions') }}</th>
</tr>

<!-- Media Headers -->
<tr x-show="resource === 'media'" data-resource="media">
    <th scope="col" class="cursor-pointer" @click="toggleSort('id'); currentPage = 1; updateUrl()">
        ID <i class="fa-solid" :class="sortBy === 'id' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('type'); currentPage = 1; updateUrl()">
        {{ __('Type') }} <i class="fa-solid" :class="sortBy === 'type' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('title'); currentPage = 1; updateUrl()">
        {{ __('Title') }} <i class="fa-solid" :class="sortBy === 'title' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('page_title'); currentPage = 1; updateUrl()">
        {{ __('Page Title') }} <i class="fa-solid" :class="sortBy === 'page_title' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col">{{ __('Path') }}</th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('created_at'); currentPage = 1; updateUrl()">
        {{ __('Created At') }} <i class="fa-solid" :class="sortBy === 'created_at' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="text-end">{{ __('Actions') }}</th>
</tr>

<!-- APIs Headers -->
<tr x-show="resource === 'apis'" data-resource="apis">
    <th scope="col" class="cursor-pointer" @click="toggleSort('id'); currentPage = 1; updateUrl()">
        ID <i class="fa-solid" :class="sortBy === 'id' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('name'); currentPage = 1; updateUrl()">
        {{ __('Name') }} <i class="fa-solid" :class="sortBy === 'name' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('category'); currentPage = 1; updateUrl()">
        {{ __('Category') }} <i class="fa-solid" :class="sortBy === 'category' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col">{{ __('Version') }}</th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('author'); currentPage = 1; updateUrl()">
        {{ __('Author') }} <i class="fa-solid" :class="sortBy === 'author' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('is_active'); currentPage = 1; updateUrl()">
        {{ __('Status') }} <i class="fa-solid" :class="sortBy === 'is_active' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="text-end">{{ __('Actions') }}</th>
</tr>

<!-- Comments Headers -->
<tr x-show="resource === 'comments'" data-resource="comments">
    <th scope="col" class="cursor-pointer" style="width: 70px;" @click="toggleSort('id'); currentPage = 1; updateUrl()">
        ID <i class="fa-solid" :class="sortBy === 'id' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" style="width: 200px;" @click="toggleSort('author_name'); currentPage = 1; updateUrl()">
        {{ __('Author') }} <i class="fa-solid" :class="sortBy === 'author_name' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col">{{ __('Comment') }}</th>
    <th scope="col" style="width: 180px;">{{ __('Article / Page') }}</th>
    <th scope="col" class="cursor-pointer" style="width: 150px;" @click="toggleSort('status'); currentPage = 1; updateUrl()">
        {{ __('Status') }} <i class="fa-solid" :class="sortBy === 'status' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" style="width: 140px;" @click="toggleSort('created_at'); currentPage = 1; updateUrl()">
        {{ __('Created At') }} <i class="fa-solid" :class="sortBy === 'created_at' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="text-end" style="width: 180px;">{{ __('Actions') }}</th>
</tr>

<!-- Queue Headers -->
<tr x-show="resource === 'queue'" data-resource="queue">
    <th scope="col" class="cursor-pointer" style="width: 80px;" @click="toggleSort('id'); currentPage = 1; updateUrl()">
        ID <i class="fa-solid" :class="sortBy === 'id' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" style="width: 120px;" @click="toggleSort('queue'); currentPage = 1; updateUrl()">
        {{ __('Queue') }} <i class="fa-solid" :class="sortBy === 'queue' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col">{{ __('Payload') }}</th>
    <th scope="col" class="cursor-pointer" style="width: 130px;" @click="toggleSort('status'); currentPage = 1; updateUrl()">
        {{ __('Status') }} <i class="fa-solid" :class="sortBy === 'status' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" style="width: 100px;" @click="toggleSort('attempts'); currentPage = 1; updateUrl()">
        {{ __('Attempts') }} <i class="fa-solid" :class="sortBy === 'attempts' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" style="width: 150px;" @click="toggleSort('available_at'); currentPage = 1; updateUrl()">
        {{ __('Available At') }} <i class="fa-solid" :class="sortBy === 'available_at' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" style="width: 150px;" @click="toggleSort('created_at'); currentPage = 1; updateUrl()">
        {{ __('Created At') }} <i class="fa-solid" :class="sortBy === 'created_at' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="text-end" style="width: 140px;">{{ __('Actions') }}</th>
</tr>

<!-- Analytics Page Views Headers -->
<tr x-show="resource === 'analytics_page_views'" data-resource="analytics_page_views">
    <th scope="col" class="cursor-pointer" style="width: 70px;" @click="toggleSort('id'); currentPage = 1; updateUrl()">
        ID <i class="fa-solid" :class="sortBy === 'id' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('url_path'); currentPage = 1; updateUrl()">
        {{ __('URL Path') }} <i class="fa-solid" :class="sortBy === 'url_path' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('referrer_host'); currentPage = 1; updateUrl()">
        {{ __('Referrer') }} <i class="fa-solid" :class="sortBy === 'referrer_host' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('utm_source'); currentPage = 1; updateUrl()">
        {{ __('UTM Source') }} <i class="fa-solid" :class="sortBy === 'utm_source' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col">{{ __('UTM Medium') }}</th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('utm_campaign'); currentPage = 1; updateUrl()">
        {{ __('UTM Campaign') }} <i class="fa-solid" :class="sortBy === 'utm_campaign' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col">{{ __('UTM Term') }}</th>
    <th scope="col">{{ __('UTM Content') }}</th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('device_type'); currentPage = 1; updateUrl()">
        {{ __('Device') }} <i class="fa-solid" :class="sortBy === 'device_type' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('created_at'); currentPage = 1; updateUrl()">
        {{ __('Created At') }} <i class="fa-solid" :class="sortBy === 'created_at' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
</tr>

<!-- Notification Templates Headers -->
<tr x-show="resource === 'templates'" data-resource="templates">
    <th scope="col" class="cursor-pointer" style="width: 70px;" @click="toggleSort('id'); currentPage = 1; updateUrl()">
        ID <i class="fa-solid" :class="sortBy === 'id' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('event_name'); currentPage = 1; updateUrl()">
        {{ __('Event Name') }} <i class="fa-solid" :class="sortBy === 'event_name' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" style="width: 120px;" @click="toggleSort('channel'); currentPage = 1; updateUrl()">
        {{ __('Channel') }} <i class="fa-solid" :class="sortBy === 'channel' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" style="width: 110px;" @click="toggleSort('language'); currentPage = 1; updateUrl()">
        {{ __('Language') }} <i class="fa-solid" :class="sortBy === 'language' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" @click="toggleSort('subject'); currentPage = 1; updateUrl()">
        {{ __('Subject') }} <i class="fa-solid" :class="sortBy === 'subject' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" style="width: 130px;" @click="toggleSort('is_active'); currentPage = 1; updateUrl()">
        {{ __('Status') }} <i class="fa-solid" :class="sortBy === 'is_active' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="cursor-pointer" style="width: 160px;" @click="toggleSort('updated_at'); currentPage = 1; updateUrl()">
        {{ __('Updated At') }} <i class="fa-solid" :class="sortBy === 'updated_at' ? (sortDesc ? 'fa-caret-down text-primary' : 'fa-caret-up text-primary') : 'fa-sort opacity-50'"></i>
    </th>
    <th scope="col" class="text-end" style="width: 180px;">{{ __('Actions') }}</th>
</tr>

