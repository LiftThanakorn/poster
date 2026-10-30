import React, { useState, useEffect } from 'react';
import DatePicker, { registerLocale } from 'react-datepicker';
import { th } from 'date-fns/locale/th';
import "react-datepicker/dist/react-datepicker.css";

// Register Thai locale
registerLocale('th', th);

export default function DateTimePicker({ value, onChange, className = '' }) {
    const parseValue = (val) => {
        if (!val) {
            const now = new Date();
            now.setHours(now.getHours() + 1);
            now.setMinutes(0);
            return now;
        }
        const d = new Date(val);
        return isNaN(d) ? new Date() : d;
    };

    const [selectedDate, setSelectedDate] = useState(parseValue(value));

    useEffect(() => {
        if (!value) {
            const init = parseValue(value);
            triggerChange(init);
        } else {
            setSelectedDate(parseValue(value));
        }
    }, [value]);

    const triggerChange = (date) => {
        if (!date) return;
        // Format to YYYY-MM-DDTHH:mm
        const pad = (n) => n.toString().padStart(2, '0');
        const formatted = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
        onChange(formatted);
    };

    const handleChange = (date) => {
        setSelectedDate(date);
        triggerChange(date);
    };

    const years = Array.from({ length: 10 }, (_, i) => new Date().getFullYear() - 2 + i);
    const months = [
        "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน",
        "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"
    ];

    return (
        <div className={`w-full ${className}`}>
            <DatePicker
                selected={selectedDate}
                onChange={handleChange}
                locale="th"
                showTimeSelect
                timeFormat="HH:mm"
                timeIntervals={5}
                timeCaption="เวลา"
                dateFormat="dd/MM/yyyy HH:mm น."
                className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm bg-white"
                calendarClassName="shadow-xl border-0 !rounded-xl !overflow-hidden font-sans"
                renderCustomHeader={({
                    date,
                    changeYear,
                    changeMonth,
                    decreaseMonth,
                    increaseMonth,
                    prevMonthButtonDisabled,
                    nextMonthButtonDisabled,
                }) => (
                    <div className="flex justify-between items-center px-3 py-2 bg-blue-50 border-b border-blue-100">
                        <button type="button" onClick={decreaseMonth} disabled={prevMonthButtonDisabled} className="p-1.5 hover:bg-white rounded-md text-gray-700 transition-colors">
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 19l-7-7 7-7"></path></svg>
                        </button>
                        <div className="flex gap-2">
                            <select
                                value={date.getMonth()}
                                onChange={({ target: { value } }) => changeMonth(parseInt(value))}
                                className="border-gray-200 rounded text-sm py-1 pl-2 pr-8 focus:ring-blue-500 focus:border-blue-500 bg-white"
                            >
                                {months.map((option, idx) => (
                                    <option key={idx} value={idx}>
                                        {option}
                                    </option>
                                ))}
                            </select>

                            <select
                                value={date.getFullYear()}
                                onChange={({ target: { value } }) => changeYear(parseInt(value))}
                                className="border-gray-200 rounded text-sm py-1 pl-2 pr-8 focus:ring-blue-500 focus:border-blue-500 bg-white"
                            >
                                {years.map(option => (
                                    <option key={option} value={option}>
                                        {option + 543}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <button type="button" onClick={increaseMonth} disabled={nextMonthButtonDisabled} className="p-1.5 hover:bg-white rounded-md text-gray-700 transition-colors">
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5l7 7-7 7"></path></svg>
                        </button>
                    </div>
                )}
            />
            
            <style>{`
                .react-datepicker-wrapper {
                    width: 100%;
                }
                .react-datepicker__time-container .react-datepicker__time .react-datepicker__time-box ul.react-datepicker__time-list {
                    padding-left: 0;
                    padding-right: 0;
                }
                .react-datepicker__header {
                    background-color: transparent;
                    border-bottom: none;
                    padding: 0;
                }
                .react-datepicker__day-name, .react-datepicker__day, .react-datepicker__time-name {
                    margin: 0.2rem;
                }
                .react-datepicker__day--selected, .react-datepicker__day--in-selecting-range, .react-datepicker__day--in-range, .react-datepicker__month-text--selected, .react-datepicker__month-text--in-selecting-range, .react-datepicker__month-text--in-range, .react-datepicker__quarter-text--selected, .react-datepicker__quarter-text--in-selecting-range, .react-datepicker__quarter-text--in-range, .react-datepicker__year-text--selected, .react-datepicker__year-text--in-selecting-range, .react-datepicker__year-text--in-range {
                    background-color: #2563eb;
                }
                .react-datepicker__day--keyboard-selected, .react-datepicker__month-text--keyboard-selected, .react-datepicker__quarter-text--keyboard-selected, .react-datepicker__year-text--keyboard-selected {
                    background-color: #3b82f6;
                }
            `}</style>
        </div>
    );
}
