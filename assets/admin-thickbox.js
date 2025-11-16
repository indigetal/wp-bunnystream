/**
 * bunny.net WordPress Plugin
 * Copyright (C) 2024-2025 BunnyWay d.o.o.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

jQuery($ => {
    window.tb_position = () => {
        const width = $(window).width();
        const height = $(window).height();

        const H = height - ((792 < width) ? 60 : 20);
        const W = (792 < width) ? 772 : width - 20;

        const tbWindow = $('#TB_window');
        if (tbWindow.length) {
            tbWindow.width(W).height(H);
            tbWindow.css({
                'margin-left': '-' + parseInt((W / 2), 10) + 'px',
                'overflow': 'hidden',
            });

            if (typeof document.body.style.maxWidth !== 'undefined') {
                tbWindow.css({
                    'top': '30px',
                    'margin-top': '0'
                });
            }

            $('#TB_ajaxContent').width(W - 30).height(H - 30);
        }

        $('a.thickbox').each(() => {
            let href = $(this).attr('href');
            if (!href) {
                return;
            }

            href = href.replace(/&width=\d+/g, '');
            href = href.replace(/&height=\d+/g, '');
            $(this).attr('href', `${href}&width=${W}&height=${H}`);
        });
    };

    $(window).on('resize', () => {
        tb_position();
    });
});
