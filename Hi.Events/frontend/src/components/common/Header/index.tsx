import {Container} from '@mantine/core';
import classes from './Header.module.scss';
import {NavLink} from "react-router";
import { getConfig } from '../../../utilites/config';

interface HeaderProps {
    rightContent?: React.ReactNode;
    fullWidth?: boolean;
}

export const Header = ({rightContent, fullWidth = false}: HeaderProps) => {
    return (
        <header className={classes.header}>
            <Container size="md" className={classes.inner} fluid={fullWidth}>
                <NavLink className={classes.logo} to={'/manage/events'} style={{ textDecoration: 'none' }}>
                    {getConfig("VITE_APP_LOGO_LIGHT") ? (
                        <img src={getConfig("VITE_APP_LOGO_LIGHT")} alt={`${getConfig("VITE_APP_NAME", "CodePode")} logo`} className={classes.logo}/>
                    ) : (
                        <span style={{ fontSize: '1.2rem', fontWeight: 800, color: '#fff', letterSpacing: '0.05em', fontFamily: 'Outfit, Inter, sans-serif' }}>
                            {getConfig("VITE_APP_NAME", "CodePode").toUpperCase()}
                        </span>
                    )}
                </NavLink>

                <div className={classes.rightContent}>
                    {rightContent}
                </div>
            </Container>
        </header>
    );
}
