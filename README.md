# dakotajune/blueprint_policies

First-class authorization policies for [Laravel Blueprint](https://blueprint.laravelshift.com). Say who can do what in `draft.yaml`; get the policy classes, the controller checks and the registration. Works for plain Laravel policies (`policies:`) and for [Laravel Nova](https://nova.laravel.com) policies (`nova_policies:`) with the same rules and the same engine.

```yaml
models:
  Post:
    user_id: id foreign
    title: string:400
    status: enum:draft,published
    softdeletes: softDeletes

controllers:
  Post:
    resource: web

policies:
  Post:
    before: admin                         # admins skip every check
    abilities:
      viewAny: public                     # guests too
      view: public
      create: verified
      update: owner | role:editor
      delete: owner & state:status=draft
      publish: role:editor & state:status=draft
  Comment:                                # defaults: reads for any signed-in user, writes for the owner
                                          # (locked, with a notice, if the model has no owner column)

nova_policies:                            # same rules, written for Nova
  Post:
    abilities:
      viewAny: auth
      update: owner | role:editor
```

`php artisan blueprint:build` writes `app/Policies/PostPolicy.php`, adds `Gate::authorize(...)` to the matching actions of `PostController`, and registers the policy where Laravel would not find it on its own. `nova_policies:` writes `app/Nova/Policies/PostPolicy.php` and sets `public static $policy` on `App\Nova\Post`.

```php
public function update(User $user, Post $post): bool
{
    return $user->getKey() === $post->user_id || $user->hasRole('editor');
}
```

## Who owns what

Generated members carry an `@generated blueprint_policies` tag. Tagged members are rewritten (or deleted) on every build; **everything untagged is yours and is never touched.**

| You change | What happens on the next build |
| --- | --- |
| The rule in the draft | the tagged method is rewritten |
| An ability is removed from the draft | its tagged method is deleted |
| You add your own method | kept |
| You delete the `@generated` tag from a method | it is yours now; the generator leaves it alone and says so |

The same goes for controllers (one tagged line per check), models (`#[UsePolicy]`) and Nova resources (`$policy`). `blueprint:erase` puts all of it back; see [Erase](#erase).

## The `policies:` section

```yaml
policies:
  Post:                      # model name; `Blog/Post` puts the policy in App\Policies\Blog
    model: Post              # when the key is not the model name
    owner: author_id         # column holding the owner's key; false = no owner. Default: detected (see config)
    before: admin            # a rule that grants everything (returns true) or falls through; false = none
    extends: BasePolicy      # parent class of the generated policy; false = none. Default: config `extends`
    abilities: ...           # which abilities, and who may use them
    except: [forceDelete]    # remove from the default set (not combined with abilities)
    controller: Admin/PostController   # wire this controller; false = none. Default: PostController
  Tag: false                 # opt out (useful with auto_policies)
```

Shorthand: `policies: [Post, Comment]` or `policies: Post, Comment`.

**Abilities.** Without `abilities:` you get `viewAny view create update delete`, plus `restore forceDelete` when the model uses soft deletes. With it, you get exactly what you list:

```yaml
abilities:
  update: owner | role:editor        # ability: rule
  publish: role:editor               # any camelCase name is an ability
  import: { rule: admin, scope: class }   # acts on the class, not a record (viewAny and create always do)
```

**Abilities you leave out.** The standard abilities (`default_abilities`, plus `restore`/`forceDelete` for soft-deleting models) that `abilities:` does not mention still get a method, answering with `unlisted_abilities` (config, default `never`, so `return false;`). Listing only what you allow is therefore enough:

```yaml
abilities:
  viewAny: auth
  view: auth        # create, update, delete, restore, forceDelete are written out and return false
```

`unlisted_abilities` takes `false`/`'never'` (the default), any rule (`'role:admin'`, `'owner'`), `true`, or `null` to write only what you list. A record rule such as `owner` cannot apply to `viewAny`/`create`; those two get the fallback and a notice. It does not apply to a policy without `abilities:` (the default rules do) or to one that uses `except:`. Controller actions follow the policy, so an unlisted `destroy` is blocked too.

Block lists with dashes are not supported (Blueprint's YAML pre-processing strips them); use the map form or a flow list `[view, update]`.

**`extends:`** makes the generated policy extend a class of your own, in `policies:` and `nova_policies:` alike. A name with a backslash is fully qualified (`\App\Support\BasePolicy`); a bare or slash name is relative to the policies namespace (`BasePolicy` is `App\Policies\BasePolicy`, or `App\Nova\Policies\BasePolicy` under `nova_policies:`). The import is added unless the parent is in the same namespace. `extends` in the config sets it for every policy (in the `nova` block for Nova only), and `extends: false` opts one policy out. It applies when the file is created; a policy that already exists is never rewritten, and you get a notice if it extends something else.

**`extends: ~` under `nova_policies:`** makes the Nova policy extend the `policies:` policy of the same model (`App\Nova\Policies\PostPolicy extends \App\Policies\PostPolicy`), so shared logic lives in one place:

```yaml
nova_policies:
  ContactType:
    extends: ~          # or an empty `extends:`
```

If that web policy does not exist and `policies:` does not define it in the same draft, an empty one is created (with a notice, and `blueprint:erase` removes it again); a web policy you wrote is only extended, never changed. Because Nova passes the resource and the web policy takes the model, PHP only accepts an override whose parameters are the same or wider. Nova policies that extend the web policy are therefore written with `?User` (rules are guarded for a missing user) and an untyped record, e.g. `view(?User $user, $post)`. A parent you wrote by hand needs `?User` too, or PHP refuses to load the pair. `extends: ~` under `policies:` does nothing.

**`before: false`** opts a policy out of a global `before` set in the config. `before: public` is refused (it would grant every ability to everyone).

## The `nova_policies:` section

Same options and same rules as `policies:`, except `controller:` (Nova calls the policy itself, nothing is wired) is replaced by `resource:`.

```yaml
nova_policies:
  Post:
    resource: Article        # the Nova resource, when it is not App\Nova\Post
    owner: user_id
    abilities:
      viewAny: auth
      update: owner | role:editor
```

- **Location.** `app/Nova/Policies/PostPolicy.php` (`App\Nova\Policies`). That is where `php artisan nova:policy --resource` puts them. Change it with `blueprint_policies.nova.namespace`.
- **Typing.** A Nova policy receives the Nova resource, not the Eloquent model: `update(User $user, \App\Nova\Post $post)`. Column checks (`owner`, `state:`) read through `$post->model()`.
- **Registration.** Nova finds a resource's policy through `public static $policy` on the resource, so the generator writes a tagged `$policy` property (and its import) there. If the resource file does not exist you get a notice (`php artisan nova:resource Post`), and the policy is still written. A `$policy` you wrote yourself is never replaced. `nova.registration: none` leaves resources alone. Neither the model nor the Gate is touched.
- **Independent of `policies:`.** The same model can be in both sections, with different rules, side by side. Two entries that would write the same file (e.g. `nova.namespace` set to `Policies`) stop the build with a message.
- **Relationship abilities.** `attachTag`-style methods (`add/attach/attachAny/detach{Model}`) are not generated; a notice says so. `replicate` falls back to your create/update rules in Nova.

## Rules

| Term | Means | Needs a record |
| --- | --- | :-: |
| `public` | anyone, guests included (the policy method takes `?User`) | |
| `auth` | any signed-in user | |
| `never` | nobody | |
| `owner` | the user's key equals the model's owner column | yes |
| `role:editor` / `role:a,b` | has the role / any of the roles | |
| `permission:posts.update` | has the permission | |
| `admin` | the configured admin check | |
| `verified` | has a verified email | |
| `state:status=draft` / `state:status=a,b` / `state:status!=archived` | the model's column equals (or is one of / is not) the value | yes |

Combine with `|` (or) and `&` (and). `&` binds tighter than `|`; there are no parentheses. Prefix a term with `!` to negate it. **Quote a rule that starts with `!`** (`hide: '!role:banned'`), because YAML reads a bare `!` as a tag.

Constants fold away (`public | owner` is `true`). When guests are allowed, every user check is guarded so a guest is denied by it instead of crashing on a null user. `auth` is a real `$user !== null` check wherever guests can reach the method and the other terms in its `&` group do not already need a user; it only folds away when the method takes a plain `User`. A *negated* user term (`!role:banned`) passes guests, because a guest does not have the role.

`role`, `permission`, `admin` and `verified` write the PHP in `config('blueprint_policies.checks')`. The defaults suit spatie/laravel-permission (`hasRole`, `can`); change them for any other role system (and for Nova alone with `nova.checks`). `state` compares with `===`, so a column cast to an enum needs handling by hand.

**A missing rule locks, it never opens.** An ability with no rule, and the default `owner` rule on a model with no owner column, produce `return false;` plus a notice that says what to set. Rules that contradict themselves (`owner` on `viewAny`, a state column that does not exist, an unknown term) stop the build with a message that names the policy and ability.

## Controllers (`policies:` only)

For every standard resource action present in a Blueprint controller, a tagged line is added at the top:

```php
Gate::authorize('update', $post); // @generated blueprint_policies
```

| Action | Ability | | Action | Ability |
| --- | --- | --- | --- | --- |
| `index` | `viewAny` | | `edit` `update` | `update` |
| `create` `store` | `create` | | `destroy` | `delete` |
| `show` | `view` | | `restore` `forceDelete` | same name |

Any other action is guarded by an ability of the same name if the policy has one. An ability that was not generated is never referenced. A method that already calls `authorize(` is left alone, and a controller that uses Blueprint's own `meta: policies` is skipped. The record variable is the controller's own parameter (`$user` in `UserController`), whatever the policy calls it. `controllers.style: authorize` writes `$this->authorize(...)` instead (needs the `AuthorizesRequests` trait). A controller is matched by name and folder: `Post` finds `PostController`, `Admin/Post` finds `Admin/PostController`; use `controller:` for anything else.

**Wiring you stop asking for is taken back.** Set `controller: false`, `controllers.wire: false` or `registration: none` and the next build removes the lines it added (and the imports only they needed). A policy that is simply missing from a later draft keeps its edits and its record, so a partial draft never un-wires the rest.

## Registration (`policies:`)

Laravel finds `App\Policies\PostPolicy` for `App\Models\Post` by itself, so nothing is added there. Anything else (a custom policy namespace, nested models) is registered with `#[UsePolicy(PostPolicy::class)]` on the model, or `Gate::policy()` in your provider's `boot()` when your Laravel has no such attribute. A model that already names a different policy is never overwritten, and an attribute you wrote yourself is never recorded as generated. Set `registration` to `attribute`, `gate` or `none` to choose.

## Erase

`blueprint:erase` deletes created files and cannot undo edits. This package does that part, from a `.blueprint_policies` manifest: tagged lines come out of controllers and the provider, `#[UsePolicy]` out of models, `$policy` out of Nova resources, imports it added go too, and each policy (Laravel and Nova) loses its generated abilities. A policy that still holds your code (or that you created first) is taken off erase's delete list by editing `.blueprint`, with a `.blueprint.bak-<timestamp>` copy made first.

## With Blueprint's own `meta: policies`

Blueprint can generate a policy from `meta: policies` on a controller. That is a separate feature and the two can coexist for different models; naming the same policy in both stops the build with a clear message. `policies:` covers everything `meta:` does and adds rules, ownership, guests, custom abilities, rebuild-safety and erase.

## Config

```bash
php artisan vendor:publish --tag=blueprint-policies-config
php artisan vendor:publish --tag=blueprint-policies-stubs        # policies:
php artisan vendor:publish --tag=blueprint-policies-nova-stubs   # nova_policies:
```

See `config/blueprint_policies.php`. Top-level keys configure `policies:`. The `nova` block configures `nova_policies:`: its own `namespace`, `resource_namespace`, `auto_policies` and `registration`, plus any top-level key (`user_model`, `default_abilities`, `default_rules`, `fallback_rule`, `owner_columns`, `before`, `unlisted_abilities`, `extends`, `checks`) to change it for Nova alone. A key the block does not set falls back to the top-level value, and a key that is set wins even when it is `false` or `null`. Overriding one entry of `checks`, `controllers` or `nova` keeps the others' defaults.

## Windows

Files with CRLF line endings stay CRLF, and every edit is reversible byte for byte. Backslash paths in `.blueprint` are recognised.

## Development

```bash
composer install
vendor/bin/pest
```
