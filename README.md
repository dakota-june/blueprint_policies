# dakotajune/blueprint_policies

First-class authorization policies for [Laravel Blueprint](https://blueprint.laravelshift.com). Say who can do what in `draft.yaml`; get the policy classes, the controller checks and the registration.

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
```

`php artisan blueprint:build` writes `app/Policies/PostPolicy.php`, adds `Gate::authorize(...)` to the matching actions of `PostController`, and registers the policy where Laravel would not find it on its own.

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

The same goes for controllers (one tagged line per check) and models (`#[UsePolicy]`). `blueprint:erase` puts all of it back; see [Erase](#erase).

## The `policies:` section

```yaml
policies:
  Post:                      # model name; `Blog/Post` puts the policy in App\Policies\Blog
    model: Post              # when the key is not the model name
    owner: author_id         # column holding the owner's key; false = no owner. Default: detected (see config)
    before: admin            # a rule that grants everything (returns true) or falls through
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

Block lists with dashes are not supported (Blueprint's YAML pre-processing strips them); use the map form or a flow list `[view, update]`.

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

Constants fold away (`public | owner` is `true`), and when guests are allowed every user check is guarded so a guest is denied by it instead of crashing on a null user.

`role`, `permission`, `admin` and `verified` write the PHP in `config('blueprint_policies.checks')`. The defaults suit spatie/laravel-permission (`hasRole`, `can`); change them for any other role system. `state` compares with `===`, so a column cast to an enum needs `checks`-style handling by hand.

**A missing rule locks, it never opens.** An ability with no rule, and the default `owner` rule on a model with no owner column, produce `return false;` plus a notice that says what to set. Rules that contradict themselves (`owner` on `viewAny`, a state column that does not exist, an unknown term) stop the build with a message that names the policy and ability.

## Controllers

For every standard resource action present in a Blueprint controller, a tagged line is added at the top:

```php
Gate::authorize('update', $post); // @generated blueprint_policies
```

| Action | Ability | | Action | Ability |
| --- | --- | --- | --- | --- |
| `index` | `viewAny` | | `edit` `update` | `update` |
| `create` `store` | `create` | | `destroy` | `delete` |
| `show` | `view` | | `restore` `forceDelete` | same name |

Any other action is guarded by an ability of the same name if the policy has one. An ability that was not generated is never referenced. A method that already calls `authorize(` is left alone, and a controller that uses Blueprint's own `meta: policies` is skipped. `controllers.style: authorize` writes `$this->authorize(...)` instead (needs the `AuthorizesRequests` trait). A controller is matched by name and folder: `Post` finds `PostController`, `Admin/Post` finds `Admin/PostController`; use `controller:` for anything else.

## Registration

Laravel finds `App\Policies\PostPolicy` for `App\Models\Post` by itself, so nothing is added there. Anything else (a custom policy namespace, nested models) is registered with `#[UsePolicy(PostPolicy::class)]` on the model, or `Gate::policy()` in your provider's `boot()` when your Laravel has no such attribute. A model that already names a different policy is never overwritten. Set `registration` to `attribute`, `gate` or `none` to choose.

## Erase

`blueprint:erase` deletes created files and cannot undo edits. This package does that part, from a `.blueprint_policies` manifest: tagged lines come out of controllers and the provider, `#[UsePolicy]` out of models, imports it added go too, and each policy loses its generated abilities. A policy that still holds your code (or that you created first) is taken off erase's delete list by editing `.blueprint`, with a `.blueprint.bak-<timestamp>` copy made first.

## With Blueprint's own `meta: policies`

Blueprint can generate a policy from `meta: policies` on a controller. That is a separate feature and the two can coexist for different models; naming the same policy in both stops the build with a clear message. `policies:` covers everything `meta:` does and adds rules, ownership, guests, custom abilities, rebuild-safety and erase.

## Config

```bash
php artisan vendor:publish --tag=blueprint-policies-config
php artisan vendor:publish --tag=blueprint-policies-stubs
```

See `config/blueprint_policies.php`: policy namespace, `auto_policies`, user model, default abilities and rules, fallback rule, owner columns, global `before`, rule `checks`, controller style, registration mode and provider path.

## Development

```bash
composer install
vendor/bin/pest
```
