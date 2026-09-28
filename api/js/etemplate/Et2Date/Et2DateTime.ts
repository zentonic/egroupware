/**
 * EGroupware eTemplate2 - Date+Time widget (WebComponent)
 *
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package etemplate
 * @subpackage api
 * @link https://www.egroupware.org
 * @author Nathan Gray
 */


import {Et2Date, formatDate, formatDateTime} from "./Et2Date";
import type {Instance} from "flatpickr/dist/types/instance";
import {default as ShortcutButtonsPlugin} from "shortcut-buttons-flatpickr/dist/shortcut-buttons-flatpickr";


import styles from "./Et2DateTime.styles";
export class Et2DateTime extends Et2Date
{
	static get styles()
	{
		return [
			...super.styles,
			styles,
		];
	}

	constructor()
	{
		super();
	}


	/**
	 * Override some flatpickr defaults to get things how we like it
	 *
	 * @see https://flatpickr.js.org/options/
	 * @returns {any}
	 */
	public getOptions()
	{
		let options = super.getOptions();

		let dateFormat = (this.egw()?.preference("dateformat") || "Y-m-d");
		let timeFormat = ((<string>this.egw()?.preference("timeformat") || "24") == "24" ? "H:i" : "h:i K");
		options.altFormat = dateFormat + " " + timeFormat;
		options.enableTime = true;
		options.time_24hr = this.egw()?.preference("timeformat", "common") == "24";
		// keep the seconds, a value the user did not change has to come back unchanged
		options.dateFormat = "Y-m-dTH:i:S\\Z";
		options.defaultHour = new Date().getHours();

		return options;
	}

	set_value(value)
	{
		// getValue() compares with the value set, which must not be the previous one here
		this._oldValue = null;
		super.set_value(value);
	}

	/**
	 * Get the value, keeping the seconds of a value the user did not change
	 *
	 * The user sees and edits the value without seconds.  flatpickr drops them, whenever it parses the displayed
	 * value again, eg. when the input loses the focus or the calendar gets closed.  As long as the displayed value
	 * is still the one set, return the value set.
	 */
	getValue()
	{
		const value = super.getValue();

		return value !== this._oldValue && this._displaysValueSet(value) ? this._oldValue : value;
	}

	/**
	 * Check if a value (in dateFormat) is displayed like the value set, ie. the user did not change it
	 *
	 * @param {string} value
	 * @returns {boolean}
	 * @protected
	 */
	protected _displaysValueSet(value : string) : boolean
	{
		if(!this._instance || !value || !this._oldValue || typeof this._oldValue !== "string")
		{
			return false;
		}
		const config = this._instance.config;
		const set = this._instance.parseDate(this._oldValue, config.dateFormat);
		const current = this._instance.parseDate(value, config.dateFormat);

		return !!set && !!current &&
			this._instance.formatDate(set, config.altFormat) === this._instance.formatDate(current, config.altFormat);
	}

	/**
	 * flatpickr's onChange callback
	 *
	 * Round to minuteIncrement, if the user changed the value.  Not if flatpickr only parsed the displayed value
	 * again, and not when flatpickr starts (onReady), which used to round the value without any user action.
	 *
	 * @param selectedDates
	 * @param dateStr
	 * @param instance
	 */
	_handleCalendarChange(selectedDates : Date[], dateStr : string, instance : Instance)
	{
		const selected = selectedDates.length ? instance.latestSelectedDateObj : null;
		if(!this.freeMinuteEntry && selected && instance.config.minuteIncrement > 1 &&
			!this._displaysValueSet(instance.formatDate(selected, instance.config.dateFormat)))
		{
			const original = selected.getMinutes();
			const bound = Math.round(original / instance.config.minuteIncrement) * instance.config.minuteIncrement;
			if(bound != original)
			{
				selected.setMinutes(bound);
				instance.setDate(selected, false);
			}
		}
		super._handleCalendarChange(selectedDates, dateStr, instance);
	}

	/**
	 * For mobile, we use a plain input of the proper type
	 * @returns {string}
	 */
	_mobileInputType() : string
	{
		return "datetime-local";
	}

	get format() : Function
	{
		// Mobile is specific about the date format
		if(typeof egwIsMobile == "function" && egwIsMobile())
		{
			// Use formatDate() for full control, formatDateTime() will add a space
			return (date) => {return formatDate(date, {dateFormat: "Y-m-dTH:i"});};
		}

		return formatDateTime;
	}

	/**
	 * Add "today" button below calendar
	 * @protected
	 */
	protected _buttonPlugin()
	{
		return ShortcutButtonsPlugin({
			button: [
				{label: this.egw().lang("ok")},
				{label: this.egw().lang("Now")}
			],
			onClick: this._handleShortcutButtonClick
		})
	}

	/**
	 * Handle clicks on scroll buttons
	 *
	 * @param e
	 */
	public handleScroll(e)
	{
		if(e.target && !e.target.dataset.direction)
		{
			return;
		}
		e.stopPropagation();

		const direction = parseInt(e.target.dataset.direction, 10) || 1;
		this.increment(direction * this.getOptions().minuteIncrement, "minute", true);
	}
}

// @ts-ignore TypeScript is not recognizing that Et2DateTime is a LitElement
customElements.define("et2-date-time", Et2DateTime);