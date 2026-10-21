import app from 'flarum/admin/app';
import FormModal from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import Switch from 'flarum/common/components/Switch';
import Stream from 'flarum/common/utils/Stream';
import { t, quietRequest } from './util';

/** Adds or edits one redirect. `attrs.redirect` to edit, `attrs.source` to prefill. */
export default class RedirectModal extends FormModal {
  oninit(vnode) {
    super.oninit(vnode);

    const r = this.attrs.redirect || {};

    this.type = Stream(r.type || 'exact');
    this.source = Stream(r.source || this.attrs.source || '');
    this.target = Stream(r.target || '');
    this.status = Stream(String(r.status || 301));
    this.mode = Stream(r.mode || 'always');
    this.keepQuery = Stream(!!r.keepQuery);
    this.external = Stream(!!r.external);
    this.lookup = Stream(r.lookup || '');
    this.runId = Stream(r.runId ? String(r.runId) : '');
    this.enabled = Stream(r.enabled !== false);
    this.note = Stream(r.note || '');
  }

  className() {
    return 'DetourRedirectModal Modal--medium';
  }

  title() {
    return this.attrs.redirect ? t('modal.edit_title') : t('modal.add_title');
  }

  content() {
    const regex = this.type() === 'regex';
    const lookup = regex && this.lookup() !== '';
    const gone = this.status() === '410';
    const external = /^https?:\/\//i.test(this.target());

    return (
      <div className="Modal-body">
        <div className="Form-group">
          <label>{t('modal.type')}</label>
          <Select
            value={this.type()}
            onchange={(v) => this.type(v)}
            options={{ exact: t('types.exact'), prefix: t('types.prefix'), regex: t('types.regex') }}
          />
          <p className="helpText">{t(`modal.type_help_${this.type()}`)}</p>
        </div>

        <div className="Form-group">
          <label for="detour-source">{t('modal.source')}</label>
          <input
            id="detour-source"
            name="source"
            className="FormControl DetourMono"
            required
            bidi={this.source}
            placeholder={regex ? '^/old/(\\d+)$' : '/old-page'}
          />
        </div>

        {regex ? (
          <div className="Form-group">
            <label>{t('modal.lookup')}</label>
            <Select
              value={this.lookup()}
              onchange={(v) => this.lookup(v)}
              options={{
                '': t('modal.lookup_none'),
                topic: t('lookups.topic'),
                post: t('lookups.post'),
                tag: t('lookups.tag'),
                user: t('lookups.user'),
              }}
            />
            <p className="helpText">{t('modal.lookup_help')}</p>
          </div>
        ) : null}

        {lookup ? (
          <div className="Form-group">
            <label for="detour-run">{t('modal.run')}</label>
            <input id="detour-run" name="runId" className="FormControl" type="number" min="1" bidi={this.runId} placeholder={t('modal.run_none')} />
            <p className="helpText">{t('modal.run_help')}</p>
          </div>
        ) : null}

        <div className="Form-group">
          <label>{t('modal.status')}</label>
          <Select
            value={this.status()}
            onchange={(v) => this.status(v)}
            options={{ 301: t('statuses.301'), 302: t('statuses.302'), 410: t('statuses.410') }}
          />
        </div>

        {!lookup && !gone ? (
          <div className="Form-group">
            <label for="detour-target">{t('modal.target')}</label>
            <input id="detour-target" name="target" className="FormControl DetourMono" required bidi={this.target} placeholder="/d/123" />
            <p className="helpText">
              {regex ? t('modal.target_help_regex') : this.type() === 'prefix' ? t('modal.target_help_prefix') : t('modal.target_help')}
            </p>
          </div>
        ) : null}

        {!lookup && !gone && external ? (
          <div className="Form-group">
            <Switch state={this.external()} onchange={this.external}>
              {t('modal.external')}
            </Switch>
            <p className="helpText">{t('modal.external_help')}</p>
          </div>
        ) : null}

        <div className="Form-group">
          <label>{t('modal.mode')}</label>
          <Select value={this.mode()} onchange={(v) => this.mode(v)} options={{ always: t('modes.always'), missing: t('modes.missing') }} />
          <p className="helpText">{t(`modal.mode_help_${this.mode()}`)}</p>
        </div>

        {!lookup && !gone ? (
          <div className="Form-group">
            <Switch state={this.keepQuery()} onchange={this.keepQuery}>
              {t('modal.keep_query')}
            </Switch>
          </div>
        ) : null}

        <div className="Form-group">
          <label for="detour-note">{t('modal.note')}</label>
          <input id="detour-note" name="note" className="FormControl" maxlength="255" bidi={this.note} />
        </div>

        <div className="Form-group">
          <Switch state={this.enabled()} onchange={this.enabled}>
            {t('modal.enabled')}
          </Switch>
        </div>

        <div className="Form-group">
          <Button type="submit" className="Button Button--primary" loading={this.loading}>
            {t('modal.save')}
          </Button>
        </div>
      </div>
    );
  }

  onsubmit(e) {
    e.preventDefault();
    this.loading = true;

    const id = this.attrs.redirect && this.attrs.redirect.id;
    const body = {
      type: this.type(),
      source: this.source(),
      target: this.target(),
      status: parseInt(this.status(), 10),
      mode: this.mode(),
      keepQuery: this.keepQuery(),
      external: this.external(),
      lookup: this.type() === 'regex' ? this.lookup() || null : null,
      runId: this.runId() ? parseInt(this.runId(), 10) : null,
      enabled: this.enabled(),
      note: this.note(),
    };

    quietRequest(id ? 'PATCH' : 'POST', id ? `/redirects/${id}` : '/redirects', body)
      .then((response) => {
        this.hide();
        this.attrs.onsave && this.attrs.onsave(response.data);
        app.alerts.show({ type: 'success' }, t('modal.saved'));
      })
      .catch((error) => {
        this.loading = false;
        this.onerror(error);
      });
  }
}
