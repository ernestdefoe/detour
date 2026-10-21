import app from 'flarum/admin/app';
import { Admin } from 'flarum/common/extenders';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import RedirectsTab from './RedirectsTab';
import MissingTab from './MissingTab';
import PresetsTab from './PresetsTab';
import ImportExportTab from './ImportExportTab';
import { t } from './util';

const TABS = { redirects: RedirectsTab, missing: MissingTab, presets: PresetsTab, csv: ImportExportTab };

/** Redirects, the 404 log, migration presets and CSV first; the settings below. */
class DetourPage extends ExtensionPage {
  oninit(vnode) {
    super.oninit(vnode);
    this.tab = 'redirects';
  }

  sections(vnode) {
    const items = super.sections(vnode);
    const Tab = TABS[this.tab];

    items.remove('permissions');
    items.setPriority('content', 10);
    items.add(
      'detour',
      <div className="ExtensionPage-settings DetourPage">
        <div className="container">
          <nav className="DetourTabs" role="tablist">
            {Object.keys(TABS).map((key) => (
              <Button
                className={`Button ${this.tab === key ? 'Button--primary' : ''}`}
                role="tab"
                aria-selected={this.tab === key ? 'true' : 'false'}
                onclick={() => (this.tab = key)}
              >
                {t(`tabs.${key}`)}
              </Button>
            ))}
          </nav>
          <Tab />
        </div>
      </div>,
      20
    );

    return items;
  }
}

const key = (name) => `ernestdefoe-detour.${name}`;

const field =
  (name, type, extra = {}) =>
  () => ({
    setting: key(name),
    type,
    label: t(`settings.${name}_label`),
    help: t(`settings.${name}_help`),
    ...extra,
  });

// Callbacks, not values: this module runs before the app has booted.
export const extend = [
  new Admin()
    .page(DetourPage)
    .setting(field('friendly_page', 'boolean'))
    .setting(field('suggestions', 'boolean'))
    .setting(field('page_title', 'text'))
    .setting(field('page_message', 'textarea'))
    .setting(field('log', 'boolean'))
    .setting(field('ignore_bots', 'boolean'))
    .setting(field('ignore_paths', 'textarea'))
    .setting(field('max_rows', 'number', { min: 100, max: 100000 }))
    .setting(field('retention_days', 'number', { min: 1, max: 3650 })),
];
